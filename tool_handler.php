<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

const SEARCH_TIMEOUT = 10.0;
const SEARCH_CONNECT_TIMEOUT = 5.0;

function build_tools_definition(): array
{
        return [
                [
                        'type' => 'function',
                        'function' => [
                                'name' => 'search_internet',
                                'description' => 'Выполняет поиск в интернете через Google Custom Search. Используй эту функцию, когда нужна актуальная информация из интернета или когда пользователь явно просит найти что-то в интернете.',
                                'parameters' => [
                                        'type' => 'object',
                                        'properties' => [
                                                'query' => [
                                                        'type' => 'string',
                                                        'description' => 'Поисковый запрос для Google Custom Search. Сформулируй запрос максимально точно и информативно.'
                                                ]
                                        ],
                                        'required' => ['query']
                                ]
                        ]
                ]
        ];
}

function perform_google_search(string $query, ?Client $mockClient = null): array
{
        $apiKey = $_ENV['GOOGLE_API_KEY'] ?? '';
        $cx = $_ENV['GOOGLE_CX'] ?? '';

        if ($apiKey === '' || $cx === '') {
                error_log('Google Custom Search API credentials not configured');
                return [
                        'error' => 'Поиск не настроен. Отсутствуют ключи API.',
                        'results' => []
                ];
        }

        try {
                $url = 'https://www.googleapis.com/customsearch/v1';
                $params = [
                        'key' => $apiKey,
                        'cx' => $cx,
                        'q' => $query,
                        'num' => 5
                ];

                if ($mockClient !== null) {
                        $searchClient = $mockClient;
                } else {
                        $searchClient = new Client([
                                'timeout' => SEARCH_TIMEOUT,
                                'connect_timeout' => SEARCH_CONNECT_TIMEOUT
                        ]);
                }

                $response = $searchClient->get($url, ['query' => $params]);
                $body = json_decode($response->getBody(), true);

                if (!is_array($body)) {
                        error_log('Invalid Google Custom Search API response');
                        return [
                                'error' => 'Неверный ответ от поискового API',
                                'results' => []
                        ];
                }

                if (isset($body['error'])) {
                        $errorMessage = $body['error']['message'] ?? 'Неизвестная ошибка Google API';
                        error_log('Google Custom Search API error: ' . $errorMessage);
                        return [
                                'error' => 'Ошибка поиска: ' . $errorMessage,
                                'results' => []
                        ];
                }

                $results = [];
                $items = $body['items'] ?? [];

                foreach ($items as $item) {
                        $results[] = [
                                'title' => $item['title'] ?? '',
                                'link' => $item['link'] ?? '',
                                'snippet' => $item['snippet'] ?? ''
                        ];
                }

                $totalResults = isset($body['searchInformation']['totalResults']) 
                        ? (int)$body['searchInformation']['totalResults'] 
                        : 0;

                return [
                        'results' => $results,
                        'total_results' => $totalResults
                ];
        } catch (ConnectException $e) {
                error_log('Google Custom Search connection error: ' . $e->getMessage());
                return [
                        'error' => 'Ошибка соединения с поисковым сервисом',
                        'results' => []
                ];
        } catch (RequestException $e) {
                $errorMessage = 'Ошибка запроса к поисковому API';
                if ($e->hasResponse()) {
                        $responseBody = (string) $e->getResponse()->getBody();
                        $errorData = json_decode($responseBody, true);
                        if (isset($errorData['error']['message'])) {
                                $errorMessage = $errorData['error']['message'];
                        }
                }
                error_log('Google Custom Search API request error: ' . $errorMessage);
                return [
                        'error' => 'Ошибка поиска: ' . $errorMessage,
                        'results' => []
                ];
        } catch (\Throwable $e) {
                error_log('Google Custom Search unexpected error: ' . $e->getMessage());
                return [
                        'error' => 'Неожиданная ошибка при выполнении поиска',
                        'results' => []
                ];
        }
}

function process_function_calls(array $toolCalls, array &$history, ?Client $mockSearchClient = null): void
{
        foreach ($toolCalls as $toolCall) {
                if (!is_array($toolCall)) {
                        continue;
                }

                $functionName = $toolCall['function']['name'] ?? '';
                $toolCallId = $toolCall['id'] ?? '';
                $argumentsJson = $toolCall['function']['arguments'] ?? '{}';

                if ($functionName === 'search_internet') {
                        $arguments = json_decode($argumentsJson, true);
                        if (!is_array($arguments) || empty($arguments['query'])) {
                                error_log('Invalid search_internet arguments: ' . $argumentsJson);
                                $result = [
                                        'error' => 'Неверный формат запроса поиска',
                                        'results' => []
                                ];
                        } else {
                                $query = trim($arguments['query']);
                                error_log('Executing Google search for query: ' . $query);
                                $result = perform_google_search($query, $mockSearchClient);
                        }

                        $history[] = [
                                'role' => 'tool',
                                'tool_call_id' => $toolCallId,
                                'content' => json_encode($result, JSON_UNESCAPED_UNICODE)
                        ];
                } else {
                        error_log('Unknown function call: ' . $functionName);
                        $history[] = [
                                'role' => 'tool',
                                'tool_call_id' => $toolCallId,
                                'content' => json_encode([
                                        'error' => 'Неизвестная функция: ' . $functionName
                                ], JSON_UNESCAPED_UNICODE)
                        ];
                }
        }
}

