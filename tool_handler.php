<?php

require_once __DIR__ . '/config.php';

use React\Http\Browser;
use React\EventLoop\Loop;

function build_tools_definition(): array
{
        return [
                [
                        'type' => 'function',
                        'function' => [
                                'name' => 'search_internet',
                                'description' => 'Используй эту функцию, когда пользователь явно просит найти что-то в интернете.',
                                'parameters' => [
                                        'type' => 'object',
                                        'properties' => [
                                                'query' => [
                                                        'type' => 'string',
                                                        'description' => 'google api search query. Сформулируй запрос максимально точно и информативно.'
                                                ]
                                        ],
                                        'required' => ['query']
                                ]
                        ]
                ]
        ];
}

function perform_google_search(string $query, ?Browser $mockClient = null): array
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
                $urlWithParams = $url . '?' . http_build_query($params);

                $loop = Loop::get();
                $result = null;
                $promiseResolved = false;
                
                if ($mockClient !== null) {
                        $searchClient = $mockClient;
                } else {
                        require_once __DIR__ . '/config.php';
                        $searchClient = create_google_search_client($loop);
                }

                $promise = $searchClient->get($urlWithParams);
                $promise->then(function ($response) use (&$result, &$promiseResolved, $loop) {
                        $body = json_decode((string)$response->getBody(), true);
                        $result = $body;
                        $promiseResolved = true;
                        $loop->stop();
                })->otherwise(function ($e) use (&$result, &$promiseResolved, $loop) {
                        error_log('Google Custom Search error: ' . $e->getMessage());
                        $result = ['error' => ['message' => $e->getMessage()]];
                        $promiseResolved = true;
                        $loop->stop();
                });
                
                // Блокируем выполнение до получения результата
                $endTime = microtime(true) + SEARCH_TIMEOUT;
                while (!$promiseResolved && microtime(true) < $endTime) {
                        $loop->run();
                }
                
                if (!$promiseResolved) {
                        error_log('Google Custom Search timeout');
                        return [
                                'error' => 'Таймаут при выполнении поиска',
                                'results' => []
                        ];
                }
                
                $body = $result;

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
        } catch (\Throwable $e) {
                error_log('Google Custom Search unexpected error: ' . $e->getMessage());
                return [
                        'error' => 'Неожиданная ошибка при выполнении поиска',
                        'results' => []
                ];
        }
}

function process_function_calls(array $toolCalls, array &$history, ?Browser $mockSearchClient = null): void
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

