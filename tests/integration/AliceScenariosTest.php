<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../alice_handler.php';

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

class AliceScenariosTest extends TestCase
{
        private string $tempDir;
        private string $originalEnvKey;
        private ?Client $mockClient = null;

        protected function setUp(): void
        {
                $this->tempDir = sys_get_temp_dir() . '/alice_test_' . uniqid();
                mkdir($this->tempDir . '/pending', 0777, true);
                mkdir($this->tempDir . '/conversation', 0777, true);
                mkdir($this->tempDir . '/models', 0777, true);

                $this->originalEnvKey = $_ENV['OPENROUTER_API_KEY'] ?? '';
                $_ENV['OPENROUTER_API_KEY'] = 'test_key';
                $_ENV['MODEL_ID'] = 'test/model';
                $_ENV['STORAGE_DIR'] = $this->tempDir;
                $_ENV['MODEL_LIST_PATH'] = $this->tempDir . '/models.txt';
                $_ENV['MODEL_STATE_PATH'] = $this->tempDir . '/model_state.json';

                file_put_contents($this->tempDir . '/models.txt', "test/model 1000\n");

                if (!isset($_SERVER['REQUEST_METHOD'])) {
                        $_SERVER['REQUEST_METHOD'] = 'POST';
                }
                unset($GLOBALS['__PHP_INPUT_MOCK__']);
        }

        protected function tearDown(): void
        {
                if (is_dir($this->tempDir)) {
                        $files = new \RecursiveIteratorIterator(
                                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                                \RecursiveIteratorIterator::CHILD_FIRST
                        );
                        foreach ($files as $file) {
                                if ($file->isDir()) {
                                        rmdir($file->getPathname());
                                } else {
                                        unlink($file->getPathname());
                                }
                        }
                        rmdir($this->tempDir);
                }

                if ($this->originalEnvKey !== '') {
                        $_ENV['OPENROUTER_API_KEY'] = $this->originalEnvKey;
                } else {
                        unset($_ENV['OPENROUTER_API_KEY']);
                }

                unset($_SERVER['REQUEST_METHOD']);
                unset($GLOBALS['__PHP_INPUT_MOCK__']);
        }

        private function loadFixture(string $filename): array
        {
                $path = dirname(__DIR__) . '/fixtures/' . $filename;
                if (!file_exists($path)) {
                        throw new \RuntimeException("Fixture file not found: {$filename} at {$path}");
                }
                $content = file_get_contents($path);
                $data = json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \RuntimeException("Invalid JSON in fixture: {$filename}");
                }
                return $data;
        }

        private function setupMockGoogleSearchClient(): Client
        {
                $googleResponse = $this->loadFixture('google_search_response.json');
                $mockHandler = new MockHandler([
                        new Response(200, ['Content-Type' => 'application/json'], json_encode($googleResponse))
                ]);
                $handlerStack = HandlerStack::create($mockHandler);
                return new Client(['handler' => $handlerStack]);
        }

        private function setupMockClient(array $responses, ?int $delayMicroseconds = null): void
        {
                $mockResponses = [];
                foreach ($responses as $response) {
                        if (is_string($response)) {
                                $response = $this->loadFixture($response);
                        }
                        $mockResponses[] = new Response(200, ['Content-Type' => 'application/json'], json_encode($response));
                }

                $mockHandler = new MockHandler($mockResponses);
                $handlerStack = HandlerStack::create($mockHandler);
                
                if ($delayMicroseconds !== null) {
                        $handlerStack->push(function (callable $handler) use ($delayMicroseconds) {
                                return function ($request, array $options) use ($handler, $delayMicroseconds) {
                                        usleep($delayMicroseconds);
                                        return $handler($request, $options);
                                };
                        });
                }
                
                $this->mockClient = new Client(['handler' => $handlerStack]);
        }

        private function mockPhpInput(array $input): void
        {
                $GLOBALS['__PHP_INPUT_MOCK__'] = json_encode($input);
        }

        private function captureOutput(callable $callback): string
        {
                $outputLevel = ob_get_level();
                ob_start();
                try {
                        $callback();
                        $output = ob_get_contents();
                        while (ob_get_level() > $outputLevel) {
                                ob_end_clean();
                        }
                        return trim($output);
                } catch (\Throwable $e) {
                        while (ob_get_level() > $outputLevel) {
                                ob_end_clean();
                        }
                        throw $e;
                }
        }

        private function extractLastJson(string $output): ?array
        {
                $output = trim($output);
                if (empty($output)) {
                        return null;
                }
                
                $pos = strrpos($output, '{"session"');
                if ($pos !== false) {
                        $lastJson = substr($output, $pos);
                        $decoded = json_decode($lastJson, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                return $decoded;
                        }
                }
                
                $decoded = json_decode($output, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        return $decoded;
                }
                
                return null;
        }

        public function test_scenario_1_initial_request(): void
        {
                $this->setupMockClient(['openrouter_initial_response.json']);

                $input = [
                        'session' => [
                                'session_id' => 'test_session_1',
                                'user_id' => 'test_user'
                        ],
                        'request' => [
                                'original_utterance' => '',
                                'command' => ''
                        ],
                        'version' => '1.0'
                ];

                $this->mockPhpInput($input);

                $output = $this->captureOutput(function () {
                        $handler = new AliceHandler($this->mockClient);
                        $handler->handleRequest();
                });

                $response = json_decode($output, true);
                $this->assertIsArray($response);
                $this->assertArrayHasKey('response', $response);
                $this->assertNotEmpty($response['response']['text']);
        }

        public function test_scenario_2_initial_then_say_hello(): void
        {
                $input1 = [
                        'session' => [
                                'session_id' => 'test_session_2',
                                'user_id' => 'test_user'
                        ],
                        'request' => [
                                'original_utterance' => '',
                                'command' => ''
                        ],
                        'version' => '1.0'
                ];

                $this->mockPhpInput($input1);
                $this->setupMockClient(['openrouter_initial_response.json']);

                $output1 = $this->captureOutput(function () {
                        $handler = new AliceHandler($this->mockClient);
                        $handler->handleRequest();
                });

                $response1 = json_decode($output1, true);
                $this->assertIsArray($response1);
                $this->assertArrayHasKey('response', $response1);

                sleep(1);

                $input2 = [
                        'session' => [
                                'session_id' => 'test_session_2',
                                'user_id' => 'test_user'
                        ],
                        'request' => [
                                'original_utterance' => 'say hello',
                                'command' => ''
                        ],
                        'version' => '1.0'
                ];

                $this->mockPhpInput($input2);
                $this->setupMockClient(['openrouter_say_hello_response.json']);

                $output2 = '';
                try {
                        $output2 = $this->captureOutput(function () {
                                $handler = new AliceHandler($this->mockClient);
                                $handler->handleRequest();
                        });
                } catch (\Throwable $e) {
                        $this->fail('Exception in scenario 2 second request: ' . $e->getMessage());
                }

                $this->assertNotEmpty($output2, 'Output2 should not be empty, got: ' . var_export($output2, true));
                if (empty($output2)) {
                        return;
                }
                $response2 = $this->extractLastJson($output2);
                if ($response2 === null) {
                        $response2 = json_decode($output2, true);
                }
                $this->assertIsArray($response2, 'Response2 should be array, got: ' . var_export($response2, true) . ', output: ' . substr($output2, 0, 200));
                $this->assertArrayHasKey('response', $response2);
                $this->assertStringContainsString('Hello', $response2['response']['text']);
        }

        public function test_scenario_3_slow_request_timeout(): void
        {
                $this->setupMockClient(['openrouter_slow_response.json'], 5000000);

                $input = [
                        'session' => [
                                'session_id' => 'test_session_3',
                                'user_id' => 'test_user'
                        ],
                        'request' => [
                                'original_utterance' => 'Сгенерируй очень длинный ответ',
                                'command' => ''
                        ],
                        'version' => '1.0'
                ];

                $this->mockPhpInput($input);

                $output = $this->captureOutput(function () {
                        $handler = new AliceHandler($this->mockClient);
                        $handler->handleRequest();
                });

                $response = json_decode($output, true);
                $this->assertIsArray($response);
                $this->assertArrayHasKey('response', $response);
                $this->assertStringContainsString('Готово', $response['response']['text']);
        }

        public function test_scenario_4_internet_search(): void
        {
                $this->setupMockClient(['openrouter_search_tool_call.json', 'openrouter_search_final_response.json']);

                $_ENV['GOOGLE_API_KEY'] = 'test_google_key';
                $_ENV['GOOGLE_CX'] = 'test_cx';

                $input = [
                        'session' => [
                                'session_id' => 'test_session_4',
                                'user_id' => 'test_user'
                        ],
                        'request' => [
                                'original_utterance' => 'who is us president at the moment? Use internet search.',
                                'command' => ''
                        ],
                        'version' => '1.0'
                ];

                $this->mockPhpInput($input);
                
                $mockGoogleClient = $this->setupMockGoogleSearchClient();

                $output = $this->captureOutput(function () use ($mockGoogleClient) {
                        $handler = new AliceHandler($this->mockClient, $mockGoogleClient);
                        $handler->handleRequest();
                });

                $response = json_decode($output, true);
                $this->assertIsArray($response);
                $this->assertArrayHasKey('response', $response);
                $this->assertNotEmpty($response['response']['text']);
        }
}

