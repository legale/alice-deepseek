<?php
require 'vendor/autoload.php';
require_once 'config.php';
require_once 'message_builder.php';
require_once 'tool_handler.php';
require_once 'error_formatter.php';
require_once 'storage.php';
require_once 'model_manager.php';
require_once 'ai_processor.php';

use React\Http\Browser;
use React\EventLoop\LoopInterface;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

class AliceHandler
{
    private const SESSION_RESET_MESSAGE = 'Ответ так и не сформировался, давайте попробуем заново.';
    private const MAX_RESPONSE_LENGTH = 1024;

    private Browser $client;
    private ?Browser $mockSearchClient;
    private string $model_id;
    private int $max_tokens;
    private string $apiKey;
    private string $pendingDir;
    private string $conversationDir;
    private array $modelList = [];
    private string $modelStatePath;

    private const MODEL_SWITCH_TRIGGERS = [
        'переключи модель',
        'смени модель'
    ];
    private const HELP_COMMANDS = [
        'помощь',
        'что ты умеешь',
        'что ты умеешь?'
    ];
    private const RESET_COMMANDS = [
        'сброс',
        'сбрось',
        'очисти',
        'очистить'
    ];
    private const CURRENT_MODEL_COMMANDS = [
        'текущая модель',
        'какая модель',
        'какая модель сейчас',
        'название модели',
        'модель'
    ];
    private const HELP_MESSAGE = 'Я голосовой помощник по типу chatGPT. Я отвечаю на любые вопросы, 
    со мной можно вести длинный разговор на любую тему! Держу большой контекст (132к), а если GPT-OSS надоест, могу переключатся
     между моделями, не теряя нить разговора. Спроси что угодно или скажи «переключи модель», чтобы выбрать другую модель.';

    public function __construct(?Browser $client = null, ?Browser $mockSearchClient = null, ?LoopInterface $loop = null)
    {
        load_config();
        $this->mockSearchClient = $mockSearchClient;

        $this->model_id = $_ENV['MODEL_ID'] ?? '';
        $this->apiKey = $_ENV['OPENROUTER_API_KEY'] ?? ($_ENV['GEMINI_API_KEY'] ?? '');
        if ($this->apiKey === '' && $client === null) {
            throw new \RuntimeException('API key is not configured.');
        }

        if ($client === null) {
            $loop = $loop ?? Loop::get();
            $this->client = create_openrouter_react_client($loop);
        } else {
            $this->client = $client;
        }

        $storageBaseDir = $_ENV['STORAGE_DIR'] ?? __DIR__ . '/storage';
        $this->pendingDir = $storageBaseDir . '/pending';
        if (!is_dir($this->pendingDir)) {
            @mkdir($this->pendingDir, 0777, true);
        }

        $this->conversationDir = $storageBaseDir . '/conversations';
        if (!is_dir($this->conversationDir)) {
            @mkdir($this->conversationDir, 0777, true);
        }

        $this->modelStatePath = $_ENV['MODEL_STATE_PATH'] ?? __DIR__ . '/storage/model_state.json';
        $modelListPath = $_ENV['MODEL_LIST_PATH'] ?? __DIR__ . '/models.txt';
        $this->modelList = load_model_list($modelListPath);
        $this->max_tokens = 0;
        sync_model_state($this->modelList, $this->model_id, $this->modelStatePath);

        if ($this->model_id === '') {
            throw new \RuntimeException('model id is not configured. Add MODEL_ID to .env or fill models.txt');
        }

        if (!empty($this->modelList[$this->model_id])) {
            $this->max_tokens = $this->modelList[$this->model_id][1];
        }
    }

    public function handleRequest(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        $this->processAliceRequest();
    }

    private function processAliceRequest(): void
    {
        $requestStartTime = microtime(true);
        
        $inputRaw = $GLOBALS['__PHP_INPUT_MOCK__'] ?? file_get_contents('php://input');
        $input = json_decode($inputRaw, true) ?? [];
        $sessionId = $input['session']['session_id'] ?? null;

        if ($sessionId === null) {
            http_response_code(400);
            echo 'Bad Request';
            return;
        }

        $responseTemplate = [
            'session' => $input['session'],
            'version' => $input['version'] ?? '1.0',
            'response' => [
                'end_session' => false,
                'text' => '',
                'tts' => ''
            ]
        ];

        $pendingState = load_pending_state($sessionId, $this->pendingDir);
        
        // Проверяем, есть ли новое сообщение от пользователя
        $utterance = $input['request']['original_utterance'] ?? '';
        $userMessage = $utterance !== '' ? clean_input($utterance) : '';
        $isGotovo = mb_strtolower(trim($userMessage)) === 'готово';
        $hasNewMessage = $utterance !== '' && !$isGotovo;
        
        // Если пользователь отправил новое сообщение (не "готово"), очищаем pending state
        if ($pendingState !== null && $hasNewMessage) {
            clear_pending_state($sessionId, $this->pendingDir);
            $pendingState = null; // Сбрасываем, чтобы не обрабатывать дальше
        }
        
        // Обрабатываем pending state только если нет нового сообщения или это "готово"
        if ($pendingState !== null) {
            $pendingResponse = $this->handlePendingState($sessionId, $pendingState, $responseTemplate, $isGotovo);
            
            if ($pendingResponse !== null) {
                $this->sendResponseAndExit($pendingResponse);
                return;
            }
        }
        
        // Если это "готово", но нет pending state - возвращаем сообщение и выходим
        if ($isGotovo) {
            $responseTemplate['response']['text'] = 'Нет ожидающих ответов. Задайте вопрос.';
            $this->sendResponseAndExit($responseTemplate);
            return;
        }

        $history = load_conversation($sessionId, $this->conversationDir);
        
        if ($utterance !== '') {
            // Если это "готово", не добавляем в историю и не передаем в LLM
            if ($isGotovo) {
                // Уже обработано выше в блоке pending state
                return;
            }
            
            $history[] = create_user_message($userMessage);
            save_conversation($sessionId, $history, $this->conversationDir);

            if ($this->processResetCommand($userMessage, $sessionId, $responseTemplate)) {
                $this->sendResponseAndExit($responseTemplate);
                return;
            }

            if ($this->processHelpCommand($userMessage, $history, $sessionId, $responseTemplate)) {
                $this->sendResponseAndExit($responseTemplate);
                return;
            }

            if ($this->processModelSwitchCommand($userMessage, $history, $sessionId, $responseTemplate)) {
                $this->sendResponseAndExit($responseTemplate);
                return;
            }

            if ($this->processCurrentModelCommand($userMessage, $responseTemplate)) {
                $this->sendResponseAndExit($responseTemplate);
                return;
            }

            try {
                $requestStartTime = microtime(true);
                $responseDeadline = $requestStartTime + QUICK_RESPONSE_TIMEOUT;
                $aiDeadline = $requestStartTime + MAX_WAIT_SECONDS; // Полный таймаут для AI

                $saveConversationCallback = function(array $hist) use ($sessionId) {
                        save_conversation($sessionId, $hist, $this->conversationDir);
                };

                $processFunctionCallsCallback = function(array $toolCalls, array &$hist) {
                        process_function_calls($toolCalls, $hist, $this->mockSearchClient);
                };

                // Запускаем запрос к AI с проверкой времени
                $finalResponse = null;
                $intermediateResponseSent = false;
                
                try {
                        // Запускаем запрос к AI, но проверяем время
                        $finalResponse = $this->waitForAiResponseWithTimeout(
                                $requestStartTime,
                                $responseDeadline,
                                $aiDeadline,
                                $history,
                                $saveConversationCallback,
                                $processFunctionCallsCallback,
                                $responseTemplate,
                                $intermediateResponseSent,
                                $sessionId
                        );
                } catch (\Throwable $e) {
                        error_log('Error in waitForAiResponseWithTimeout: ' . $e->getMessage());
                        $finalResponse = [
                                'text' => format_ai_error($e),
                                'message' => create_assistant_message_from_text(format_ai_error($e))
                        ];
                }

                // Если промежуточный ответ был отправлен - продолжаем ждать в фоне
                if ($intermediateResponseSent) {
                        // Pending state уже создан в waitForAiResponseWithTimeout
                        $pendingState = load_pending_state($sessionId, $this->pendingDir);
                        $startedAt = $pendingState !== null && isset($pendingState['started_at']) ? $pendingState['started_at'] : microtime(true);
                        $this->continueBackgroundFetch($sessionId, $history, $startedAt, $finalResponse);
                        return;
                }

                // Если ответ пришел вовремя - отдаем сразу
                if ($finalResponse !== null) {
                        $responseTemplate['response']['text'] = $this->truncateResponse($finalResponse['text']);
                        save_conversation($sessionId, $history, $this->conversationDir);
                        $this->sendResponseAndExit($responseTemplate);
                        return;
                }
            } catch (\Throwable $e) {
                error_log('Unexpected error: ' . $e->getMessage());
                $errorMessage = TECH_ERROR_MESSAGE . ' (обработка запроса)';
                $responseTemplate['response']['text'] = $errorMessage;
                $history[] = create_assistant_message_from_text($errorMessage);
                save_conversation($sessionId, $history, $this->conversationDir);
                $this->sendResponseAndExit($responseTemplate);
                return;
            }
        }

        $responseTemplate['response']['text'] = $this->buildGreetingMessage();
        $this->sendResponseAndExit($responseTemplate);
    }

    private function processResetCommand(string $userMessage, string $sessionId, array &$responseTemplate): bool
    {
        if (!$this->isResetCommand($userMessage)) {
            return false;
        }
        clear_conversation($sessionId, $this->conversationDir);
        clear_pending_state($sessionId, $this->pendingDir);
        $responseTemplate['response']['text'] = 'Контекст сброшен';
        return true;
    }

    private function processHelpCommand(string $userMessage, array &$history, string $sessionId, array &$responseTemplate): bool
    {
        if (!$this->isHelpCommand($userMessage)) {
            return false;
        }

        $history[] = create_assistant_message_from_text(self::HELP_MESSAGE);
        save_conversation($sessionId, $history, $this->conversationDir);
        $responseTemplate['response']['text'] = self::HELP_MESSAGE;
        return true;
    }

    private function processModelSwitchCommand(string $userMessage, array &$history, string $sessionId, array &$responseTemplate): bool
    {
        if (!$this->containsModelSwitchCommand($userMessage)) {
            return false;
        }

        switch_to_next_model($this->modelList, $this->model_id, $this->max_tokens, $this->modelStatePath);
        $switchText = 'переключаю на: ' . display_model_name($this->model_id);
        $history[] = create_assistant_message_from_text($switchText);
        save_conversation($sessionId, $history, $this->conversationDir);
        $responseTemplate['response']['text'] = $switchText;

        return true;
    }

    private function processCurrentModelCommand(string $userMessage, array &$responseTemplate): bool
    {
        if (!$this->isCurrentModelCommand($userMessage)) {
            return false;
        }
        $displayName = display_model_name($this->model_id);
        $responseTemplate['response']['text'] = 'Текущая модель: ' . $displayName;
        return true;
    }

    private function isResetCommand(string $text): bool
    {
        $normalized = $this->normalizeCommand($text);
        return in_array($normalized, self::RESET_COMMANDS, true);
    }

    private function isHelpCommand(string $text): bool
    {
        $normalized = $this->normalizeCommand($text);
        return in_array($normalized, self::HELP_COMMANDS, true);
    }

    private function containsModelSwitchCommand(string $text): bool
    {
        $haystack = mb_strtolower($text);
        foreach (self::MODEL_SWITCH_TRIGGERS as $trigger) {
            if (mb_strpos($haystack, $trigger) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isCurrentModelCommand(string $text): bool
    {
        $normalized = $this->normalizeCommand($text);
        $haystack = mb_strtolower($text);
        foreach (self::CURRENT_MODEL_COMMANDS as $command) {
            if (mb_strpos($haystack, $command) !== false) {
                return true;
            }
        }
        return false;
    }

    private function buildGreetingMessage(): string
    {
        $displayName = display_model_name($this->model_id);
        return sprintf('Говорит %s! Спроси что угодно или скажи «помощь», чтобы услышать инструкцию.', $displayName);
    }

    private function normalizeCommand(string $text): string
    {
        return trim(rtrim(mb_strtolower(trim($text)), "?!.,\t\n\r\0\x0B"));
    }

    private function createPendingState(string $sessionId, array $history): array
    {
        $state = [
            'status' => 'pending',
            'started_at' => microtime(true),
            'history' => array_values($history)
        ];

        save_pending_state($sessionId, $state, $this->pendingDir);
        return $state;
    }

    private function waitForAiResponseWithTimeout(
        float $requestStartTime,
        float $responseDeadline,
        float $aiDeadline,
        array &$history,
        callable $saveConversationCallback,
        callable $processFunctionCallsCallback,
        array &$responseTemplate,
        bool &$intermediateResponseSent,
        string $sessionId
    ): ?array {
        // Создаем EventLoop для асинхронной обработки
        $loop = Loop::get();
        
        // Проверяем, не истекло ли время
        $timeoutForRequest = $responseDeadline - $requestStartTime;
        if ($timeoutForRequest <= 0) {
                $intermediateResponseSent = true;
                return null;
        }
        
        // Создаем Deferred для синхронизации результата
        $resultDeferred = new \React\Promise\Deferred();
        $finalResponse = null;
        $responseReceived = false;
        $timer = null;
        
        // Создаем pending state ДО отправки промежуточного ответа, чтобы знать started_at
        $pendingState = $this->createPendingState($sessionId, $history);
        $pendingStartedAt = $pendingState['started_at'];
        
        // Запускаем таймер на responseDeadline
        $timer = $loop->addTimer($timeoutForRequest, function () use (
                &$intermediateResponseSent,
                &$responseReceived,
                &$responseTemplate,
                $resultDeferred,
                $responseDeadline,
                $pendingStartedAt
        ) {
                if (!$responseReceived) {
                        $intermediateResponseSent = true;
                        
                        // Вычисляем прошедшее время с момента создания pending state
                        $now = microtime(true);
                        $elapsedSeconds = (int)($now - $pendingStartedAt);
                        $waitingMessage = sprintf(WAITING_MESSAGE_FORMAT, $elapsedSeconds);
                        $responseTemplate['response']['text'] = $waitingMessage;
                        $this->sendResponseAndExit($responseTemplate);
                        
                        $resultDeferred->resolve(null);
                }
        });
        
        // Запускаем асинхронный запрос к AI
        $aiPromise = process_ai_request_loop(
                $this->client,
                $this->model_id,
                $history,
                $requestStartTime,
                $aiDeadline,
                $saveConversationCallback,
                $processFunctionCallsCallback,
                $loop
        );
        
        $aiPromise->then(function ($response) use (
                &$finalResponse,
                &$responseReceived,
                $timer,
                $loop,
                $resultDeferred,
                $requestStartTime,
                $responseDeadline
        ) {
                $responseReceived = true;
                $elapsed = microtime(true) - $requestStartTime;
                
                // Если ответ пришел до дедлайна - отменяем таймер и возвращаем ответ
                if ($elapsed < $responseDeadline) {
                        if ($timer !== null) {
                                $loop->cancelTimer($timer);
                        }
                        $finalResponse = $response;
                        $resultDeferred->resolve($response);
                } else {
                        // Ответ пришел после дедлайна, но мы уже отправили промежуточный ответ
                        $finalResponse = $response;
                        $resultDeferred->resolve(null);
                }
        })->otherwise(function (\Throwable $e) use (
                &$responseReceived,
                $timer,
                $loop,
                $resultDeferred,
                $requestStartTime,
                $responseDeadline
        ) {
                $responseReceived = true;
                $elapsed = microtime(true) - $requestStartTime;
                
                error_log('Error in process_ai_request_loop: ' . $e->getMessage());
                $errorResponse = [
                        'text' => format_ai_error($e),
                        'message' => create_assistant_message_from_text(format_ai_error($e))
                ];
                
                if ($elapsed < $responseDeadline) {
                        if ($timer !== null) {
                                $loop->cancelTimer($timer);
                        }
                        $resultDeferred->resolve($errorResponse);
                } else {
                        $resultDeferred->resolve(null);
                }
        });
        
        // Блокируем выполнение до получения результата
        $result = null;
        $promiseResolved = false;
        $promise = $resultDeferred->promise();
        $promise->then(function ($res) use (&$result, &$promiseResolved, $loop) {
                $result = $res;
                $promiseResolved = true;
                $loop->stop();
        })->otherwise(function ($e) use (&$result, &$promiseResolved, $loop) {
                error_log('Error in promise: ' . $e->getMessage());
                $result = null;
                $promiseResolved = true;
                $loop->stop();
        });
        
        // Запускаем EventLoop до получения результата
        $endTime = microtime(true) + $timeoutForRequest + 1.0;
        while (!$promiseResolved && microtime(true) < $endTime) {
                $loop->run();
        }
        
        return $result;
    }

    private function continueBackgroundFetch(string $sessionId, array $history, float $startedAt, ?array $partialResponse = null): void
    {
        $deadline = $startedAt + MAX_WAIT_SECONDS;
        ignore_user_abort(true);

        try {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                save_pending_state($sessionId, [
                        'status' => 'expired',
                        'started_at' => $startedAt,
                        'history' => $history
                ], $this->pendingDir);
                return;
            }

            $saveConversationCallback = function(array $hist) use ($sessionId) {
                    save_conversation($sessionId, $hist, $this->conversationDir);
            };

            $processFunctionCallsCallback = function(array $toolCalls, array &$hist) {
                    process_function_calls($toolCalls, $hist, $this->mockSearchClient);
            };

            // Если уже есть частичный ответ (например, ошибка) - используем его
            if ($partialResponse !== null) {
                    $finalResponse = $partialResponse;
            } else {
                    // Продолжаем ждать ответ от AI
                    $loop = Loop::get();
                    $aiPromise = process_ai_request_loop(
                            $this->client,
                            $this->model_id,
                            $history,
                            $startedAt,
                            $deadline,
                            $saveConversationCallback,
                            $processFunctionCallsCallback,
                            $loop
                    );
                    
                    $promiseResolved = false;
                    $aiPromise->then(function ($res) use (&$finalResponse, &$promiseResolved, $loop) {
                            $finalResponse = $res;
                            $promiseResolved = true;
                            $loop->stop();
                    })->otherwise(function ($e) use (&$finalResponse, &$promiseResolved, $loop) {
                            error_log('Error in background fetch: ' . $e->getMessage());
                            $finalResponse = [
                                    'text' => format_ai_error($e),
                                    'message' => create_assistant_message_from_text(format_ai_error($e))
                            ];
                            $promiseResolved = true;
                            $loop->stop();
                    });
                    
                    // Запускаем EventLoop до получения результата
                    $endTime = microtime(true) + MAX_WAIT_SECONDS;
                    while (!$promiseResolved && microtime(true) < $endTime) {
                            $loop->run();
                    }
            }

            save_conversation($sessionId, $history, $this->conversationDir);
            
            // Если finalResponse null, создаем fallback ответ из последнего сообщения ассистента в истории
            if ($finalResponse === null) {
                $lastAssistantMessage = null;
                for ($i = count($history) - 1; $i >= 0; $i--) {
                    if (isset($history[$i]['role']) && $history[$i]['role'] === 'assistant') {
                        $lastAssistantMessage = $history[$i];
                        break;
                    }
                }
                
                if ($lastAssistantMessage !== null) {
                    $text = build_display_text_from_parts($lastAssistantMessage['content'] ?? []);
                    if ($text !== '' && strpos($text, TECH_ERROR_MESSAGE) === false) {
                        $finalResponse = [
                            'text' => $text,
                            'message' => $lastAssistantMessage
                        ];
                    } else {
                        $finalResponse = [
                            'text' => 'Не удалось получить ответ от модели',
                            'message' => create_assistant_message_from_text('Не удалось получить ответ от модели')
                        ];
                    }
                } else {
                    $finalResponse = [
                        'text' => 'Не удалось получить ответ от модели',
                        'message' => create_assistant_message_from_text('Не удалось получить ответ от модели')
                    ];
                }
            }
            
            save_pending_state($sessionId, [
                    'status' => 'ready',
                    'started_at' => $startedAt,
                    'history' => $history,
                    'response' => $finalResponse,
                    'conversation_updated' => true
            ], $this->pendingDir);
        } catch (\Throwable $e) {
            error_log('Background fetch failure: ' . $e->getMessage());
            $errorText = format_generic_error($e);
            $payload = create_assistant_payload_from_text($errorText);
            $this->appendAssistantMessage($sessionId, $payload['message']);
            save_pending_state($sessionId, [
                    'status' => 'ready',
                    'started_at' => $startedAt,
                    'history' => $history,
                    'response' => $payload,
                    'conversation_updated' => true
            ], $this->pendingDir);
        }
    }

    private function handlePendingState(string $sessionId, array $pendingState, array $responseTemplate, bool $isGotovo = false): ?array
    {
        $now = microtime(true);
        $status = $pendingState['status'] ?? '';
        $startedAt = $pendingState['started_at'] ?? microtime(true);
        $deadline = $startedAt + MAX_WAIT_SECONDS;

        if ($status === 'ready' && array_key_exists('response', $pendingState) && $pendingState['response'] !== null) {
            $responsePayload = $pendingState['response'];
            $conversationUpdated = (bool) ($pendingState['conversation_updated'] ?? false);

            if (is_array($responsePayload)) {
                    $text = $responsePayload['text'] ?? (TECH_ERROR_MESSAGE . ' (нет ответа)');
                    $responseTemplate['response']['text'] = $this->truncateResponse($text);

                if (!$conversationUpdated && !empty($responsePayload['message'])) {
                    $this->appendAssistantMessage($sessionId, $responsePayload['message']);
                }
            } else {
                $text = (string) $responsePayload;
                $responseTemplate['response']['text'] = $this->truncateResponse($text);

                if (!$conversationUpdated) {
                    $this->appendAssistantMessage($sessionId, create_assistant_message_from_text($text));
                }
            }

            clear_pending_state($sessionId, $this->pendingDir);
            return $responseTemplate;
        }

        if ($status === 'expired' || $now >= $deadline) {
            clear_pending_state($sessionId, $this->pendingDir);
            clear_conversation($sessionId, $this->conversationDir);
            $responseTemplate['response']['text'] = self::SESSION_RESET_MESSAGE;
            return $responseTemplate;
        }

        if ($status === 'pending') {
            // Если это "готово", возвращаем сообщение что ответ еще формируется
            if ($isGotovo) {
                $responseTemplate['response']['text'] = 'Ответ еще формируется. Подождите немного.';
                return $responseTemplate;
            }
            
            // Вычисляем прошедшее время с начала запроса
            $elapsedSeconds = (int)($now - $startedAt);
            $waitingMessage = sprintf(WAITING_MESSAGE_FORMAT, $elapsedSeconds);
            $responseTemplate['response']['text'] = $waitingMessage;
            return $responseTemplate;
        }

        return null;
    }

    private function saveExpiredState(string $sessionId, float $startedAt, array $history): void
    {
        save_pending_state($sessionId, [
                'status' => 'expired',
                'started_at' => $startedAt,
                'history' => $history
        ], $this->pendingDir);
    }

    private function truncateResponse(string $response): string
    {
        return mb_strlen($response) > self::MAX_RESPONSE_LENGTH ? mb_substr($response, 0, self::MAX_RESPONSE_LENGTH) : $response;
    }

    private function appendAssistantMessage(string $sessionId, array $message): void
    {
        $history = load_conversation($sessionId, $this->conversationDir);
        $history[] = $message;
        save_conversation($sessionId, $history, $this->conversationDir);
    }

    private function sendResponse(array $responseData): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode($responseData, JSON_UNESCAPED_UNICODE);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            if (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @flush();
        }
    }

    private function releaseSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function sendResponseAndExit(array $responseData): void
    {
        $this->sendResponse($responseData);
        $this->releaseSession();
    }
}

(new AliceHandler())->handleRequest();
