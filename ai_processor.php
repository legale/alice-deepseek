<?php

require_once 'config.php';
require_once 'message_builder.php';
require_once 'tool_handler.php';
require_once 'error_formatter.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

const MAX_HTTP_TIMEOUT = 4.0;
const MAX_CONNECT_TIMEOUT = 3.0;
const MIN_TIMEOUT = 1.0;

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
        $fallback = create_assistant_message_from_text(\TECH_ERROR_MESSAGE);
        return [
                'text' => \TECH_ERROR_MESSAGE,
                'message' => $fallback
        ];
}

function request_ai_response(Client $client, string $modelId, array $history, float $timeoutSeconds): array
{
        $messages = build_messages($history);
        $timeout = max(MIN_TIMEOUT, min($timeoutSeconds, MAX_HTTP_TIMEOUT));

        $payload = [
                'model' => $modelId,
                'messages' => $messages,
                'tools' => build_tools_definition(),
        ];
        log_ai_request($payload);

        $response = $client->post('chat/completions', [
                'timeout' => $timeout,
                'connect_timeout' => min(MAX_CONNECT_TIMEOUT, $timeout),
                'json' => $payload,
        ]);

        $body = json_decode($response->getBody(), true);
        return extract_response_payload($body);
}

function process_ai_request_loop(
        Client $client,
        string $modelId,
        array &$history,
        float $startTime,
        float $deadline,
        callable $saveConversationCallback,
        callable $processFunctionCallsCallback
): ?array {
        $maxIterations = 3;
        $iteration = 0;
        $finalResponse = null;

        while ($iteration < $maxIterations) {
                $elapsed = microtime(true) - $startTime;
                if ($elapsed >= $deadline) {
                        break;
                }
                
                $remainingTime = max(MIN_TIMEOUT, $deadline - $elapsed);
                if ($remainingTime <= 0) {
                        break;
                }
                
                try {
                        $aiPayload = request_ai_response($client, $modelId, $history, $remainingTime);
                } catch (\Throwable $e) {
                        error_log('Error in request_ai_response: ' . $e->getMessage());
                        break;
                }
                
                $history[] = $aiPayload['message'];
                
                if (!empty($aiPayload['tool_calls']) && is_array($aiPayload['tool_calls'])) {
                        $processFunctionCallsCallback($aiPayload['tool_calls'], $history);
                        $saveConversationCallback($history);
                        $iteration++;
                        
                        $elapsed = microtime(true) - $startTime;
                        if ($elapsed >= $deadline) {
                                break;
                        }
                        
                        continue;
                }
                
                $finalResponse = $aiPayload;
                break;
        }

        if ($finalResponse === null && !empty($history)) {
                $lastMessage = end($history);
                if ($lastMessage['role'] === 'assistant') {
                        $finalResponse = [
                                'text' => build_display_text_from_parts($lastMessage['content'] ?? []),
                                'message' => $lastMessage
                        ];
                }
        }

        return $finalResponse;
}

