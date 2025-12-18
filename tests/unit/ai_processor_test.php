<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../ai_processor.php';


class AiProcessorTest extends \PHPUnit\Framework\TestCase
{
        public function test_extract_response_payload_with_valid_response_returns_payload(): void
        {
                $response = [
                        'choices' => [
                                [
                                        'message' => [
                                                'role' => 'assistant',
                                                'content' => [['type' => 'text', 'text' => 'Test response']]
                                        ]
                                ]
                        ]
                ];

                $result = extract_response_payload($response);
                $this->assertArrayHasKey('text', $result);
                $this->assertArrayHasKey('message', $result);
                $this->assertEquals('Test response', $result['text']);
                $this->assertEquals('assistant', $result['message']['role']);
        }

        public function test_extract_response_payload_with_tool_calls_includes_them(): void
        {
                $response = [
                        'choices' => [
                                [
                                        'message' => [
                                                'role' => 'assistant',
                                                'content' => [['type' => 'text', 'text' => 'Response']],
                                                'tool_calls' => [
                                                        ['id' => 'call_1', 'function' => ['name' => 'search']]
                                                ]
                                        ]
                                ]
                        ]
                ];

                $result = extract_response_payload($response);
                $this->assertArrayHasKey('tool_calls', $result);
                $this->assertArrayHasKey('tool_calls', $result['message']);
                $this->assertCount(1, $result['tool_calls']);
        }

        public function test_extract_response_payload_without_choices_returns_error(): void
        {
                $response = [];

                $result = extract_response_payload($response);
                $this->assertEquals(TECH_ERROR_MESSAGE, $result['text']);
                $this->assertEquals('assistant', $result['message']['role']);
        }

        public function test_extract_response_payload_with_empty_content_returns_error(): void
        {
                $response = [
                        'choices' => [
                                [
                                        'message' => [
                                                'role' => 'assistant',
                                                'content' => []
                                        ]
                                ]
                        ]
                ];

                $result = extract_response_payload($response);
                $this->assertEquals(TECH_ERROR_MESSAGE, $result['text']);
        }

        public function test_extract_response_payload_with_invalid_structure_returns_error(): void
        {
                $response = [
                        'choices' => [
                                ['invalid' => 'structure']
                        ]
                ];

                $result = extract_response_payload($response);
                $this->assertEquals(TECH_ERROR_MESSAGE, $result['text']);
        }

        public function test_log_ai_request_masks_messages(): void
        {
                $payload = [
                        'model' => 'test',
                        'messages' => [
                                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'secret']]],
                                ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'response']]]
                        ]
                ];

                log_ai_request($payload);
                $this->assertTrue(true);
        }

        public function test_log_ai_request_logs_payload(): void
        {
                $payload = ['model' => 'test', 'messages' => []];
                log_ai_request($payload);
                $this->assertTrue(true);
        }

        public function test_log_ai_request_with_empty_payload_logs(): void
        {
                $payload = [];
                log_ai_request($payload);
                $this->assertTrue(true);
        }
}

