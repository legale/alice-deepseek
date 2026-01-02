<?php

require_once 'config.php';
require_once 'message_builder.php';
require_once 'tool_handler.php';
require_once 'error_formatter.php';

use React\Http\Browser;
use React\Promise\PromiseInterface;
use React\Promise\Deferred;
use Psr\Http\Message\ResponseInterface;


const MAX_CONNECT_TIMEOUT = 3.0;
function log_ai_request(array $payload): void
{
        $clone = $payload;
        if (isset($clone['messages'])) {
                $clone['messages'] = array_map(static function ($message) {
                        if (!is_array($message) || empty($message['content'])) {
                                return $message;
                        }

                        $message['content'] = '[omitted ' . (is_array($message['content']) ? count($message['content']) : 1) . ' parts]';
                        return $message;
                }, $clone['messages']);
        }

        error_log('OpenRouter request payload: ' . json_encode($clone, JSON_UNESCAPED_UNICODE));
}

function extract_response_payload(array $response): array
{
        if (!empty($response['choices'][0]['message'])) {
                $message = $response['choices'][0]['message'];
                $parts = normalize_content_parts($message['content'] ?? []);
                
                $result = [
                        'text' => build_display_text_from_parts($parts),
                        'message' => [
                                'role' => $message['role'] ?? 'assistant',
                                'content' => $parts
                        ]
                ];

                if (!empty($message['tool_calls']) && is_array($message['tool_calls'])) {
                        $result['tool_calls'] = $message['tool_calls'];
                        $result['message']['tool_calls'] = $message['tool_calls'];
                }

                if (!empty($parts)) {
                        return $result;
                }
        }

        error_log('Unexpected OpenRouter response: ' . json_encode($response));
        $errorMessage = TECH_ERROR_MESSAGE . ' (неверный формат ответа)';
        $fallback = create_assistant_message_from_text($errorMessage);
        return [
                'text' => $errorMessage,
                'message' => $fallback
        ];
}

function request_ai_response(Browser $client, string $modelId, array $history, float $timeoutSeconds, \React\EventLoop\LoopInterface $loop, bool $withTools = true): PromiseInterface
{
        $messages = build_messages($history);
        $payload = [
                'model' => $modelId,
                'messages' => $messages,
        ];
        if ($withTools) {
                $payload['tools'] = build_tools_definition();
        }
        log_ai_request($payload);

        $baseUri = get_openrouter_base_uri();
        $headers = get_openrouter_headers();
        $url = $baseUri . 'chat/completions';
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $promise = $client->post($url, $headers, $body);

        // Добавляем таймаут для запроса
        $deferred = new Deferred();
        $timeoutTimer = null;

        $promise->then(
                function (ResponseInterface $response) use ($deferred, &$timeoutTimer, $loop, $client, $modelId, $history, $timeoutSeconds, $withTools) {
                        if ($timeoutTimer !== null) {
                                $loop->cancelTimer($timeoutTimer);
                        }
                        
                        // Проверяем статус код
                        $statusCode = $response->getStatusCode();
                        if ($statusCode < 200 || $statusCode >= 300) {
                                $responseBody = '';
                                $bodyLength = 0;
                                try {
                                        $responseBody = (string)$response->getBody();
                                        $bodyLength = strlen($responseBody);
                                } catch (\Throwable $e) {
                                        $responseBody = '(unable to read body: ' . $e->getMessage() . ')';
                                        $bodyLength = strlen($responseBody);
                                }
                                
                                // Логируем в error_log для видимости в /var/log/php-error.log
                                $statusText = $statusCode === 400 ? 'Bad Request' : ($statusCode === 429 ? 'Too Many Requests' : 'Error');
                                error_log('Error in request_ai_response: HTTP status code ' . $statusCode . ' (' . $statusText . ')');
                                error_log('Response body length: ' . $bodyLength);
                                error_log('Response body: ' . ($responseBody !== '' ? $responseBody : '(EMPTY)'));
                                
                                // Если модель не поддерживает tools и мы пытались их использовать - повторим запрос без tools
                                if ($statusCode === 404 && $withTools) {
                                        $errorData = json_decode($responseBody, true);
                                        if (is_array($errorData) && isset($errorData['error']['message']) && 
                                            strpos($errorData['error']['message'], 'tool use') !== false) {
                                                error_log('Model does not support tools, retrying without tools');
                                                $retryPromise = request_ai_response($client, $modelId, $history, $timeoutSeconds, $loop, false);
                                                $retryPromise->then(function ($result) use ($deferred) {
                                                        $deferred->resolve($result);
                                                })->otherwise(function ($error) use ($deferred) {
                                                        $deferred->reject($error);
                                                });
                                                return;
                                        }
                                }
                                
                                $deferred->reject(new \RuntimeException('HTTP status code ' . $statusCode . ' (' . $responseBody . ')'));
                                return;
                        }
                        
                        $responseBody = (string)$response->getBody();
                        $data = json_decode($responseBody, true);
                        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                                $deferred->reject(new \RuntimeException('Invalid JSON response from OpenRouter'));
                                return;
                        }
                        $result = extract_response_payload($data);
                        $deferred->resolve($result);
                },
                function (\Throwable $error) use ($deferred, &$timeoutTimer, $loop, $client, $modelId, $history, $timeoutSeconds, $withTools) {
                        if ($timeoutTimer !== null) {
                                $loop->cancelTimer($timeoutTimer);
                        }
                        
                        // Логируем в error_log для видимости в /var/log/php-error.log
                        error_log('Error in request_ai_response (rejection): ' . $error->getMessage());
                        error_log('Error class: ' . get_class($error));
                        if ($error->getPrevious() !== null) {
                                error_log('Previous error: ' . $error->getPrevious()->getMessage());
                        }
                        
                        // Пытаемся извлечь тело ответа из ошибки, если это HTTP ошибка
                        $responseBody = '';
                        if (method_exists($error, 'getResponse')) {
                                try {
                                        $errorResponse = $error->getResponse();
                                        if ($errorResponse !== null && method_exists($errorResponse, 'getBody')) {
                                                $responseBody = (string)$errorResponse->getBody();
                                                error_log('Response body from error: ' . ($responseBody !== '' ? $responseBody : '(EMPTY)'));
                                                
                                                // Если модель не поддерживает tools и мы пытались их использовать - повторим запрос без tools
                                                if ($withTools && $errorResponse->getStatusCode() === 404) {
                                                        $errorData = json_decode($responseBody, true);
                                                        if (is_array($errorData) && isset($errorData['error']['message']) && 
                                                            strpos($errorData['error']['message'], 'tool use') !== false) {
                                                                error_log('Model does not support tools, retrying without tools');
                                                                $retryPromise = request_ai_response($client, $modelId, $history, $timeoutSeconds, $loop, false);
                                                                $retryPromise->then(function ($result) use ($deferred) {
                                                                        $deferred->resolve($result);
                                                                })->otherwise(function ($retryError) use ($deferred) {
                                                                        $deferred->reject($retryError);
                                                                });
                                                                return;
                                                        }
                                                }
                                        }
                                } catch (\Throwable $e) {
                                        error_log('Failed to extract response body from error: ' . $e->getMessage());
                                }
                        }
                        
                        $deferred->reject($error);
                }
        );

        // Устанавливаем таймаут
        $timeoutTimer = $loop->addTimer($timeoutSeconds, function () use ($deferred, &$timeoutTimer, $timeoutSeconds) {
                $timeoutTimer = null;
                $deferred->reject(new \RuntimeException('Request timeout after ' . $timeoutSeconds . ' seconds'));
        });

        return $deferred->promise();
}

function process_ai_request_loop(
        Browser $client,
        string $modelId,
        array &$history,
        float $startTime,
        float $deadline,
        callable $saveConversationCallback,
        callable $processFunctionCallsCallback,
        \React\EventLoop\LoopInterface $loop
): PromiseInterface {
        $maxIterations = 3;
        
        // Рекурсивная функция для обработки итераций
        $processIteration = function ($iteration) use (
                &$processIteration,
                $client,
                $modelId,
                &$history,
                $startTime,
                $deadline,
                $saveConversationCallback,
                $processFunctionCallsCallback,
                $loop,
                $maxIterations
        ): PromiseInterface {
                // Проверяем deadline
                $elapsed = microtime(true) - $startTime;
                if ($elapsed >= $deadline || $iteration >= $maxIterations) {
                        // Таймаут или достигнут лимит итераций
                        if (!empty($history)) {
                                $lastMessage = end($history);
                                if ($lastMessage['role'] === 'assistant') {
                                        $finalResponse = [
                                                'text' => build_display_text_from_parts($lastMessage['content'] ?? []),
                                                'message' => $lastMessage
                                        ];
                                        return \React\Promise\resolve($finalResponse);
                                }
                        }
                        $errorResponse = [
                                'text' => 'Не удалось получить ответ от модели: превышено время ожидания',
                                'message' => create_assistant_message_from_text('Не удалось получить ответ от модели: превышено время ожидания')
                        ];
                        return \React\Promise\resolve($errorResponse);
                }
                
                // Пересчитываем оставшееся время для запроса
                $remainingTime = $deadline - microtime(true);
                if ($remainingTime <= 0) {
                        $errorResponse = [
                                'text' => 'Не удалось получить ответ от модели: превышено время ожидания',
                                'message' => create_assistant_message_from_text('Не удалось получить ответ от модели: превышено время ожидания')
                        ];
                        return \React\Promise\resolve($errorResponse);
                }
                
                // Запускаем запрос к AI
                return request_ai_response($client, $modelId, $history, $remainingTime, $loop)
                        ->then(function ($aiPayload) use (
                                &$history,
                                $processFunctionCallsCallback,
                                $saveConversationCallback,
                                &$processIteration,
                                $iteration,
                                $startTime,
                                $deadline,
                                $loop
                        ) {
                                $history[] = $aiPayload['message'];
                                
                                // Если есть tool calls - обрабатываем их и продолжаем итерацию
                                if (!empty($aiPayload['tool_calls']) && is_array($aiPayload['tool_calls'])) {
                                        $processFunctionCallsCallback($aiPayload['tool_calls'], $history);
                                        $saveConversationCallback($history);
                                        
                                        // Проверяем deadline перед следующей итерацией
                                        $elapsed = microtime(true) - $startTime;
                                        if ($elapsed >= $deadline) {
                                                return \React\Promise\resolve($aiPayload);
                                        }
                                        
                                        // Продолжаем следующую итерацию
                                        return $processIteration($iteration + 1);
                                }
                                
                                // Финальный ответ получен
                                return \React\Promise\resolve($aiPayload);
                        })
                        ->otherwise(function (\Throwable $e) {
                                error_log('Error in request_ai_response: ' . $e->getMessage());
                                $errorMessage = format_ai_error($e);
                                return [
                                        'text' => $errorMessage,
                                        'message' => create_assistant_message_from_text($errorMessage)
                                ];
                        });
        };
        
        return $processIteration(0);
}

function format_ai_error(\Throwable $e): string
{
        // Проверяем на таймаут
        if ($e instanceof \RuntimeException && strpos($e->getMessage(), 'timeout') !== false) {
                return 'Не удалось получить ответ от модели: превышено время ожидания';
        }
        
        // Проверяем на HTTP ошибки
        if ($e instanceof \RuntimeException && strpos($e->getMessage(), 'HTTP status code') !== false) {
                return 'Не удалось получить ответ от модели: ' . $e->getMessage();
        }
        
        // Проверяем на ошибки соединения
        if ($e instanceof \RuntimeException && (strpos($e->getMessage(), 'connection') !== false || strpos($e->getMessage(), 'Connection') !== false)) {
                return 'Не удалось получить ответ от модели: ошибка соединения';
        }
        
        return 'Не удалось получить ответ от модели: ' . $e->getMessage();
}

