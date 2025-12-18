<?php
require 'vendor/autoload.php';
require_once 'config.php';
require_once 'message_builder.php';
require_once 'tool_handler.php';
require_once 'error_formatter.php';
require_once 'storage.php';
require_once 'model_manager.php';
require_once 'ai_processor.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

class AliceHandler
{
    private const SESSION_RESET_MESSAGE = 'Ответ так и не сформировался, давайте попробуем заново.';
    private const MAX_RESPONSE_LENGTH = 1024;

    private Client $client;
    private ?Client $mockSearchClient;
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
    private const HELP_MESSAGE = 'Я голосовой помощник по типу chatGPT. Я отвечаю на любые вопросы, 
    со мной можно вести длинный разговор на любую тему! Держу большой контекст (132к), а если GPT-OSS надоест, могу переключатся
     между моделями, не теряя нить разговора. Спроси что угодно или скажи «переключи модель», чтобы выбрать другую модель.';

    public function __construct(?Client $client = null, ?Client $mockSearchClient = null)
    {
        load_config();
        $this->mockSearchClient = $mockSearchClient;

        $this->model_id = $_ENV['MODEL_ID'] ?? '';
        $this->apiKey = $_ENV['OPENROUTER_API_KEY'] ?? ($_ENV['GEMINI_API_KEY'] ?? '');
        if ($this->apiKey === '' && $client === null) {
            throw new \RuntimeException('API key is not configured.');
        }

        $this->client = $client ?? create_openrouter_client();

        $this->pendingDir = __DIR__ . '/storage/pending';
        if (!is_dir($this->pendingDir)) {
            mkdir($this->pendingDir, 0777, true);
        }

        $this->conversationDir = __DIR__ . '/storage/conversations';
        if (!is_dir($this->conversationDir)) {
            mkdir($this->conversationDir, 0777, true);
        }

        $this->modelStatePath = __DIR__ . '/storage/model_state.json';
        $this->modelList = load_model_list(__DIR__ . '/models.txt');
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
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        $this->processAliceRequest();
    }

    private function processAliceRequest(): void
    {
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
        if ($pendingState !== null) {
            $pendingResponse = $this->handlePendingState($sessionId, $pendingState, $responseTemplate);
            if ($pendingResponse !== null) {
                $this->sendResponse($pendingResponse);
                $this->releaseSession();
                return;
            }
        }

        $history = load_conversation($sessionId, $this->conversationDir);
        $utterance = $input['request']['original_utterance'] ?? '';
        if ($utterance !== '') {
            $userMessage = clean_input($utterance);
            $history[] = create_user_message($userMessage);
            save_conversation($sessionId, $history, $this->conversationDir);

            if ($this->processHelpCommand($userMessage, $history, $sessionId, $responseTemplate)) {
                $this->sendResponse($responseTemplate);
                $this->releaseSession();
                return;
            }

            if ($this->processModelSwitchCommand($userMessage, $history, $sessionId, $responseTemplate)) {
                $this->sendResponse($responseTemplate);
                $this->releaseSession();
                return;
            }

            try {
                $requestStartTime = microtime(true);
                $responseDeadline = $requestStartTime + QUICK_RESPONSE_TIMEOUT;
                $processDeadline = $responseDeadline;

                $saveConversationCallback = function(array $hist) use ($sessionId) {
                        save_conversation($sessionId, $hist, $this->conversationDir);
                };

                $processFunctionCallsCallback = function(array $toolCalls, array &$hist) {
                        process_function_calls($toolCalls, $hist, $this->mockSearchClient);
                };

                $finalResponse = null;
                
                try {
                        $finalResponse = process_ai_request_loop(
                                $this->client,
                                $this->model_id,
                                $history,
                                $requestStartTime,
                                $processDeadline,
                                $saveConversationCallback,
                                $processFunctionCallsCallback
                        );
                } catch (\Throwable $e) {
                        error_log('Error in process_ai_request_loop: ' . $e->getMessage());
                }

                $elapsed = microtime(true) - $requestStartTime;
                
                if ($elapsed >= QUICK_RESPONSE_TIMEOUT) {
                        $pendingState = $this->createPendingState($sessionId, $history);
                        $responseTemplate['response']['text'] = WAITING_MESSAGE;
                        $this->sendResponse($responseTemplate);
                        $this->releaseSession();
                        $this->continueBackgroundFetch($sessionId, $history, $pendingState['started_at']);
                        return;
                }
                
                if ($finalResponse === null) {
                        $pendingState = $this->createPendingState($sessionId, $history);
                        $responseTemplate['response']['text'] = WAITING_MESSAGE;
                        $this->sendResponse($responseTemplate);
                        $this->releaseSession();
                        $this->continueBackgroundFetch($sessionId, $history, $pendingState['started_at']);
                        return;
                }

                $responseTemplate['response']['text'] = $this->truncateResponse($finalResponse['text']);
                save_conversation($sessionId, $history, $this->conversationDir);
                $this->sendResponse($responseTemplate);
                $this->releaseSession();
                return;
            } catch (ConnectException $e) {
                $errno = get_curl_errno($e);
                if (is_timeout_errno($errno)) {
                    $pendingState = $this->createPendingState($sessionId, $history);
                        $responseTemplate['response']['text'] = WAITING_MESSAGE;
                    $this->sendResponse($responseTemplate);
                    $this->releaseSession();
                    $this->continueBackgroundFetch($sessionId, $history, $pendingState['started_at']);
                    return;
                }

                error_log('OpenRouter connection error: ' . $e->getMessage());
                error_log('OpenRouter connection context: ' . json_encode($e->getHandlerContext()));
                $errorText = format_connect_error($e);
                $responseTemplate['response']['text'] = $errorText;
                $history[] = create_assistant_message_from_text($errorText);
                save_conversation($sessionId, $history, $this->conversationDir);
                $this->sendResponse($responseTemplate);
                $this->releaseSession();
                return;
            } catch (RequestException $e) {
                if (is_timeout_exception($e)) {
                    $pendingState = $this->createPendingState($sessionId, $history);
                        $responseTemplate['response']['text'] = WAITING_MESSAGE;
                    $this->sendResponse($responseTemplate);
                    $this->releaseSession();
                    $this->continueBackgroundFetch($sessionId, $history, $pendingState['started_at']);
                    return;
                }

                error_log('OpenRouter API error: ' . $e->getMessage());
                $errorText = format_request_error($e);
                $responseTemplate['response']['text'] = $errorText;
                $history[] = create_assistant_message_from_text($errorText);
                save_conversation($sessionId, $history, $this->conversationDir);
                $this->sendResponse($responseTemplate);
                $this->releaseSession();
                return;
            }
        }

        $responseTemplate['response']['text'] = $this->buildGreetingMessage();
        $this->sendResponse($responseTemplate);
        $this->releaseSession();
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


    private function buildGreetingMessage(): string
    {
        $displayName = display_model_name($this->model_id);
        return sprintf('Говорит %s! Спроси что угодно или скажи «помощь», чтобы услышать инструкцию.', $displayName);
    }


    private function normalizeCommand(string $text): string
    {
        $normalized = mb_strtolower(trim($text));
        $normalized = rtrim($normalized, "?!.," . "\t\n\r\0\x0B");
        return trim($normalized);
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

    private function continueBackgroundFetch(string $sessionId, array $history, float $startedAt): void
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

            $finalResponse = process_ai_request_loop(
                    $this->client,
                    $this->model_id,
                    $history,
                    $startedAt,
                    $deadline,
                    $saveConversationCallback,
                    $processFunctionCallsCallback
            );

            save_conversation($sessionId, $history, $this->conversationDir);
            save_pending_state($sessionId, [
                    'status' => 'ready',
                    'started_at' => $startedAt,
                    'history' => $history,
                    'response' => $finalResponse,
                    'conversation_updated' => true
            ], $this->pendingDir);
        } catch (ConnectException $e) {
            $errno = get_curl_errno($e);
            if (is_timeout_errno($errno)) {
                    save_pending_state($sessionId, [
                            'status' => 'expired',
                            'started_at' => $startedAt,
                            'history' => $history
                    ], $this->pendingDir);
                    error_log('OpenRouter timeout for session ' . $sessionId);
                    return;
            }

            error_log('OpenRouter connection error (background): ' . $e->getMessage());
            error_log('OpenRouter connection context (background): ' . json_encode($e->getHandlerContext()));
            $errorText = format_connect_error($e);
            $payload = create_assistant_payload_from_text($errorText);
            $this->appendAssistantMessage($sessionId, $payload['message']);
            save_pending_state($sessionId, [
                    'status' => 'ready',
                    'started_at' => $startedAt,
                    'history' => $history,
                    'response' => $payload,
                    'conversation_updated' => true
            ], $this->pendingDir);
        } catch (RequestException $e) {
            if (is_timeout_exception($e)) {
                    save_pending_state($sessionId, [
                            'status' => 'expired',
                            'started_at' => $startedAt,
                            'history' => $history
                    ], $this->pendingDir);
                    error_log('OpenRouter timeout for session ' . $sessionId);
                    return;
            }

            error_log('OpenRouter API error (background): ' . $e->getMessage());
            $errorText = format_request_error($e);
            $payload = create_assistant_payload_from_text($errorText);
            $this->appendAssistantMessage($sessionId, $payload['message']);
            save_pending_state($sessionId, [
                    'status' => 'ready',
                    'started_at' => $startedAt,
                    'history' => $history,
                    'response' => $payload,
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

    private function handlePendingState(string $sessionId, array $pendingState, array $responseTemplate): ?array
    {
        $status = $pendingState['status'] ?? '';
        $startedAt = $pendingState['started_at'] ?? microtime(true);
        $deadline = $startedAt + MAX_WAIT_SECONDS;
        $now = microtime(true);

        if ($status === 'ready' && array_key_exists('response', $pendingState)) {
            $responsePayload = $pendingState['response'];
            $conversationUpdated = (bool) ($pendingState['conversation_updated'] ?? false);

            if (is_array($responsePayload)) {
                $text = $responsePayload['text'] ?? TECH_ERROR_MESSAGE;
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
                        $responseTemplate['response']['text'] = WAITING_MESSAGE;
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
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($responseData, JSON_UNESCAPED_UNICODE);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            @ob_end_flush();
            flush();
        }
    }

    private function releaseSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}

(new AliceHandler())->handleRequest();
