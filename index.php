<?php
require 'vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

class AliceHandler
{
    private const MODEL_ID = 'z-ai/glm-4.5-air:free';
    private const QUICK_RESPONSE_TIMEOUT = 4.2; // keep initial round-trip under Alice 4.5s SLA
    private const MAX_WAIT_SECONDS = 20.0;
    private const WAITING_MESSAGE = 'Думаю. Спросите меня через несколько секунд: Готово? и я отвечу';
    private const SESSION_RESET_MESSAGE = 'Ответ так и не сформировался, давайте попробуем заново.';
    private const TECH_ERROR_MESSAGE = 'Произошла техническая ошибка. Пожалуйста, попробуйте позже.';

    private Client $client;
    private string $apiKey;
    private array $sessionState = [];
    private string $pendingDir;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $dotenv = Dotenv::createImmutable(__DIR__);
        $dotenv->load();

        $this->apiKey = $_ENV['OPENROUTER_API_KEY'] ?? ($_ENV['GEMINI_API_KEY'] ?? '');
        if ($this->apiKey === '') {
            throw new \RuntimeException('API key is not configured.');
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];

        if (!empty($_ENV['OPENROUTER_SITE_URL'])) {
            $headers['HTTP-Referer'] = $_ENV['OPENROUTER_SITE_URL'];
        }

        if (!empty($_ENV['OPENROUTER_APP_NAME'])) {
            $headers['X-Title'] = $_ENV['OPENROUTER_APP_NAME'];
        }

        $curlOptions = [];
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $curlOptions[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }

        $streamContext = [
            'socket' => [
                'bindto' => '0:0'
            ],
            'ssl' => [
                'SNI_enabled' => true,
                'peer_name' => 'openrouter.ai',
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ];

        $clientConfig = [
            'base_uri' => 'https://openrouter.ai/api/v1/',
            'timeout' => 60.0,
            'connect_timeout' => 10.0,
            'headers' => $headers,
            'stream_context' => $streamContext
        ];

        if (!empty($curlOptions)) {
            $clientConfig['curl'] = $curlOptions;
        }

        $this->client = new Client($clientConfig);

        if (!isset($_SESSION['users_state'])) {
            $_SESSION['users_state'] = [];
        }
        $this->sessionState = &$_SESSION['users_state'];

        $this->pendingDir = __DIR__ . '/storage/pending';
        if (!is_dir($this->pendingDir)) {
            mkdir($this->pendingDir, 0777, true);
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
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
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

        $pendingState = $this->loadPendingState($sessionId);
        if ($pendingState !== null) {
            $pendingResponse = $this->handlePendingState($sessionId, $pendingState, $responseTemplate);
            if ($pendingResponse !== null) {
                $this->sendResponse($pendingResponse);
                $this->releaseSession();
                return;
            }
        }

        if (!isset($this->sessionState[$sessionId])) {
            $this->sessionState[$sessionId] = ['messages' => []];
        }

        $utterance = $input['request']['original_utterance'] ?? '';
        if ($utterance !== '') {
            $userMessage = $this->cleanInput($utterance);
            $this->sessionState[$sessionId]['messages'][] = $userMessage;
            $history = $this->sessionState[$sessionId]['messages'];

            try {
                $responseText = $this->requestAiResponse($history, self::QUICK_RESPONSE_TIMEOUT);
                $responseTemplate['response']['text'] = $this->truncateResponse($responseText);
                $this->sendResponse($responseTemplate);
                $this->releaseSession();
                return;
            } catch (ConnectException $e) {
                $errno = $this->getCurlErrno($e);
                if ($this->isTimeoutErrno($errno)) {
                    $pendingState = $this->createPendingState($sessionId, $history);
                    $responseTemplate['response']['text'] = self::WAITING_MESSAGE;
                    $this->sendResponse($responseTemplate);
                    $this->releaseSession();
                    $this->continueBackgroundFetch($sessionId, $history, $pendingState['started_at']);
                    return;
                }

                error_log('OpenRouter connection error: ' . $e->getMessage());
                error_log('OpenRouter connection context: ' . json_encode($e->getHandlerContext()));
                $responseTemplate['response']['text'] = $this->formatConnectError($e);
                $this->sendResponse($responseTemplate);
                $this->releaseSession();
                return;
            } catch (RequestException $e) {
                if ($this->isTimeoutException($e)) {
                    $pendingState = $this->createPendingState($sessionId, $history);
                    $responseTemplate['response']['text'] = self::WAITING_MESSAGE;
                    $this->sendResponse($responseTemplate);
                    $this->releaseSession();
                    $this->continueBackgroundFetch($sessionId, $history, $pendingState['started_at']);
                    return;
                }

                error_log('OpenRouter API error: ' . $e->getMessage());
                $errorText = $this->formatRequestError($e);
                $responseTemplate['response']['text'] = $errorText;
                $this->sendResponse($responseTemplate);
                $this->releaseSession();
                return;
            }
        }

        $responseTemplate['response']['text'] = 'Godeep на связи.';
        $this->sendResponse($responseTemplate);
        $this->releaseSession();
    }

    private function requestAiResponse(array $history, float $timeoutSeconds): string
    {
        $messages = $this->buildMessages($history);
        $timeout = max(1.0, $timeoutSeconds);

        $payload = [
            'model' => self::MODEL_ID,
            'messages' => $messages,
        ];
        $this->logAiRequest($payload);

        $response = $this->client->post('chat/completions', [
            'timeout' => $timeout,
            'connect_timeout' => min(5.0, $timeout),
            'json' => $payload,
        ]);

        $body = json_decode($response->getBody(), true);
        return $this->extractResponseText($body);
    }

    private function createPendingState(string $sessionId, array $history): array
    {
        $state = [
            'status' => 'pending',
            'started_at' => microtime(true),
            'history' => array_values($history)
        ];

        $this->savePendingState($sessionId, $state);
        return $state;
    }

    private function continueBackgroundFetch(string $sessionId, array $history, float $startedAt): void
    {
        $deadline = $startedAt + self::MAX_WAIT_SECONDS;
        ignore_user_abort(true);

        try {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $this->saveExpiredState($sessionId, $startedAt, $history);
                return;
            }

            $responseText = $this->requestAiResponse($history, $remaining);
            $this->savePendingState($sessionId, [
                'status' => 'ready',
                'started_at' => $startedAt,
                'history' => $history,
                'response' => $responseText
            ]);
        } catch (ConnectException $e) {
            $errno = $this->getCurlErrno($e);
            if ($this->isTimeoutErrno($errno)) {
                $this->saveExpiredState($sessionId, $startedAt, $history);
                error_log('OpenRouter timeout for session ' . $sessionId);
                return;
            }

            error_log('OpenRouter connection error (background): ' . $e->getMessage());
            error_log('OpenRouter connection context (background): ' . json_encode($e->getHandlerContext()));
            $errorText = $this->formatConnectError($e);
            $this->savePendingState($sessionId, [
                'status' => 'ready',
                'started_at' => $startedAt,
                'history' => $history,
                'response' => $errorText
            ]);
        } catch (RequestException $e) {
            if ($this->isTimeoutException($e)) {
                $this->saveExpiredState($sessionId, $startedAt, $history);
                error_log('OpenRouter timeout for session ' . $sessionId);
                return;
            }

            error_log('OpenRouter API error (background): ' . $e->getMessage());
            $errorText = $this->formatRequestError($e);
            $this->savePendingState($sessionId, [
                'status' => 'ready',
                'started_at' => $startedAt,
                'history' => $history,
                'response' => $errorText
            ]);
        } catch (\Throwable $e) {
            error_log('Background fetch failure: ' . $e->getMessage());
            $errorText = $this->formatGenericError($e);
            $this->savePendingState($sessionId, [
                'status' => 'ready',
                'started_at' => $startedAt,
                'history' => $history,
                'response' => $errorText
            ]);
        }
    }

    private function handlePendingState(string $sessionId, array $pendingState, array $responseTemplate): ?array
    {
        $status = $pendingState['status'] ?? '';
        $startedAt = $pendingState['started_at'] ?? microtime(true);
        $deadline = $startedAt + self::MAX_WAIT_SECONDS;
        $now = microtime(true);

        if ($status === 'ready' && array_key_exists('response', $pendingState)) {
            $responseTemplate['response']['text'] = $this->truncateResponse($pendingState['response']);
            $this->clearPendingState($sessionId);
            return $responseTemplate;
        }

        if ($status === 'expired' || $now >= $deadline) {
            $this->clearPendingState($sessionId);
            unset($this->sessionState[$sessionId]);
            $responseTemplate['response']['text'] = self::SESSION_RESET_MESSAGE;
            return $responseTemplate;
        }

        if ($status === 'pending') {
            $responseTemplate['response']['text'] = self::WAITING_MESSAGE;
            return $responseTemplate;
        }

        return null;
    }

    private function saveExpiredState(string $sessionId, float $startedAt, array $history): void
    {
        $this->savePendingState($sessionId, [
            'status' => 'expired',
            'started_at' => $startedAt,
            'history' => $history
        ]);
    }

    private function truncateResponse(string $response): string
    {
        return mb_strlen($response) > 1024 ? mb_substr($response, 0, 1024) : $response;
    }

    private function buildMessages(array $history): array
    {
        if (empty($history)) {
            $history = ['Отвечай на приветствие пользователя.'];
        }

        $messages = [];
        foreach ($history as $text) {
            $messages[] = [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $text
                    ]
                ]
            ];
        }

        return $messages;
    }

    private function extractResponseText(array $response): string
    {
        if (!empty($response['choices'][0]['message']['content'])) {
            $content = $response['choices'][0]['message']['content'];
            if (is_array($content)) {
                $content = implode("\n", array_map('trim', $content));
            }

            return trim((string) $content);
        }

        error_log('Unexpected OpenRouter response: ' . json_encode($response));
        return self::TECH_ERROR_MESSAGE;
    }

    private function cleanInput(string $input): string
    {
        $patterns = ['/^алиса,?\s*/ui', '/^аиса,?\s*/ui'];
        return trim(preg_replace($patterns, '', $input));
    }

    private function logAiRequest(array $payload): void
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

    private function formatRequestError(RequestException $exception): string
    {
        $code = null;
        $details = trim($exception->getMessage());

        if ($exception->hasResponse()) {
            $response = $exception->getResponse();
            $code = $response->getStatusCode();
            $details = $this->extractErrorText((string) $response->getBody());
        } else {
            $errno = $this->getCurlErrno($exception);
            if ($errno !== null) {
                $code = $errno;
            }
        }

        if ($details === '') {
            return $code === null ? '' : sprintf('Ошибка OpenRouter код=%s', $this->formatCode($code));
        }

        $codeLabel = $this->formatCode($code ?? 'N/A');
        return sprintf('Ошибка OpenRouter код=%s %s', $codeLabel, $details);
    }

    private function formatConnectError(ConnectException $exception): string
    {
        $details = trim($exception->getMessage());
        $errno = $this->getCurlErrno($exception);

        if ($details === '') {
            return $errno === null
                ? ''
                : sprintf('Ошибка соединения с OpenRouter код=%s', $this->formatCode($errno));
        }

        return sprintf(
            'Ошибка соединения с OpenRouter код=%s %s',
            $this->formatCode($errno ?? 'N/A'),
            $details
        );
    }

    private function formatGenericError(\Throwable $exception): string
    {
        $code = $exception->getCode();
        $details = trim($exception->getMessage());

        if ($details === '') {
            return $code ? sprintf('Внутренняя ошибка код=%s', $this->formatCode($code)) : '';
        }

        return sprintf('Внутренняя ошибка код=%s %s', $this->formatCode($code ?: 'N/A'), $details);
    }

    private function extractErrorText(string $body): string
    {
        return trim($body);
    }

    private function loadPendingState(string $sessionId): ?array
    {
        $path = $this->getPendingFilePath($sessionId);
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);
        return is_array($data) ? $data : null;
    }

    private function savePendingState(string $sessionId, array $state): void
    {
        $path = $this->getPendingFilePath($sessionId);
        file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function clearPendingState(string $sessionId): void
    {
        $path = $this->getPendingFilePath($sessionId);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function getPendingFilePath(string $sessionId): string
    {
        $safeId = preg_replace('/[^A-Za-z0-9_-]/', '_', $sessionId);
        return $this->pendingDir . '/' . $safeId . '.json';
    }

    private function isTimeoutException(RequestException $exception): bool
    {
        $errno = $this->getCurlErrno($exception);
        return $this->isTimeoutErrno($errno);
    }

    private function isTimeoutErrno(?int $errno): bool
    {
        $timeoutCode = defined('CURLE_OPERATION_TIMEDOUT') ? CURLE_OPERATION_TIMEDOUT : 28;
        return $errno !== null && $errno === $timeoutCode;
    }

    private function getCurlErrno($exception): ?int
    {
        $context = $exception->getHandlerContext();
        return isset($context['errno']) ? (int) $context['errno'] : null;
    }

    private function formatCode($code): string
    {
        if ($code === null || $code === '') {
            return 'N/A';
        }

        return (string) $code;
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
