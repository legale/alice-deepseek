<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../message_builder.php';


class MessageBuilderTest extends \PHPUnit\Framework\TestCase
{
        public function test_normalize_content_parts_with_string_returns_array(): void
        {
                $result = normalize_content_parts('test text');
                $this->assertIsArray($result);
                $this->assertCount(1, $result);
                $this->assertEquals('text', $result[0]['type']);
                $this->assertEquals('test text', $result[0]['text']);
        }

        public function test_normalize_content_parts_with_text_parts_returns_normalized(): void
        {
                $input = [
                        ['type' => 'text', 'text' => 'part1'],
                        ['type' => 'text', 'text' => 'part2']
                ];
                $result = normalize_content_parts($input);
                $this->assertCount(2, $result);
                $this->assertEquals('part1', $result[0]['text']);
                $this->assertEquals('part2', $result[1]['text']);
        }

        public function test_normalize_content_parts_with_mixed_types_returns_normalized(): void
        {
                $input = [
                        'string part',
                        ['type' => 'text', 'text' => 'array part'],
                        ['type' => 'image', 'url' => 'http://example.com']
                ];
                $result = normalize_content_parts($input);
                $this->assertCount(3, $result);
                $this->assertEquals('text', $result[0]['type']);
                $this->assertEquals('string part', $result[0]['text']);
                $this->assertEquals('text', $result[1]['type']);
                $this->assertEquals('array part', $result[1]['text']);
                $this->assertEquals('image', $result[2]['type']);
        }

        public function test_normalize_content_parts_with_empty_array_returns_error_message(): void
        {
                $result = normalize_content_parts([]);
                $this->assertCount(1, $result);
                $this->assertEquals('text', $result[0]['type']);
                $this->assertEquals(TECH_ERROR_MESSAGE, $result[0]['text']);
        }

        public function test_normalize_content_parts_with_invalid_elements_skips_them(): void
        {
                $input = [
                        'valid',
                        123,
                        null,
                        ['type' => 'text', 'text' => 'also valid']
                ];
                $result = normalize_content_parts($input);
                $this->assertCount(2, $result);
                $this->assertEquals('valid', $result[0]['text']);
                $this->assertEquals('also valid', $result[1]['text']);
        }

        public function test_build_messages_with_empty_history_returns_system_message(): void
        {
                $result = build_messages([]);
                $this->assertCount(1, $result);
                $this->assertEquals('system', $result[0]['role']);
                $this->assertEquals(SYSTEM_PROMPT, $result[0]['content'][0]['text']);
        }

        public function test_build_messages_with_user_messages_returns_all(): void
        {
                $history = [
                        create_user_message('Hello'),
                        create_user_message('World')
                ];
                $result = build_messages($history);
                $this->assertCount(3, $result);
                $this->assertEquals('system', $result[0]['role']);
                $this->assertEquals('user', $result[1]['role']);
                $this->assertEquals('user', $result[2]['role']);
        }

        public function test_build_messages_with_assistant_messages_returns_all(): void
        {
                $history = [
                        create_assistant_message_from_text('Response 1'),
                        create_assistant_message_from_text('Response 2')
                ];
                $result = build_messages($history);
                $this->assertCount(3, $result);
                $this->assertEquals('assistant', $result[1]['role']);
                $this->assertEquals('assistant', $result[2]['role']);
        }

        public function test_build_messages_with_tool_messages_returns_all(): void
        {
                $history = [
                        [
                                'role' => 'tool',
                                'tool_call_id' => 'call_123',
                                'content' => 'search result'
                        ]
                ];
                $result = build_messages($history);
                $this->assertCount(2, $result);
                $this->assertEquals('tool', $result[1]['role']);
                $this->assertEquals('call_123', $result[1]['tool_call_id']);
        }

        public function test_build_messages_with_tool_calls_includes_them(): void
        {
                $history = [
                        [
                                'role' => 'assistant',
                                'content' => [['type' => 'text', 'text' => 'test']],
                                'tool_calls' => [['id' => 'call_1', 'function' => ['name' => 'search']]]
                        ]
                ];
                $result = build_messages($history);
                $this->assertCount(2, $result);
                $this->assertArrayHasKey('tool_calls', $result[1]);
                $this->assertCount(1, $result[1]['tool_calls']);
        }

        public function test_build_messages_with_invalid_entries_skips_them(): void
        {
                $history = [
                        ['invalid'],
                        create_user_message('valid'),
                        ['role' => ''],
                        null
                ];
                $result = build_messages($history);
                $this->assertCount(2, $result);
                $this->assertEquals('user', $result[1]['role']);
        }

        public function test_create_user_message_with_normal_text_returns_correct_structure(): void
        {
                $result = create_user_message('Hello world');
                $this->assertEquals('user', $result['role']);
                $this->assertIsArray($result['content']);
                $this->assertEquals('text', $result['content'][0]['type']);
                $this->assertEquals('Hello world', $result['content'][0]['text']);
        }

        public function test_create_user_message_with_empty_string_returns_structure(): void
        {
                $result = create_user_message('');
                $this->assertEquals('user', $result['role']);
                $this->assertEquals('', $result['content'][0]['text']);
        }

        public function test_create_user_message_with_special_chars_returns_correct(): void
        {
                $text = 'Hello "world" & <test>';
                $result = create_user_message($text);
                $this->assertEquals($text, $result['content'][0]['text']);
        }

        public function test_create_user_message_with_long_text_returns_correct(): void
        {
                $text = str_repeat('a', 1000);
                $result = create_user_message($text);
                $this->assertEquals($text, $result['content'][0]['text']);
        }

        public function test_create_assistant_message_from_text_with_normal_text_returns_correct_structure(): void
        {
                $result = create_assistant_message_from_text('Response text');
                $this->assertEquals('assistant', $result['role']);
                $this->assertIsArray($result['content']);
                $this->assertEquals('text', $result['content'][0]['type']);
                $this->assertEquals('Response text', $result['content'][0]['text']);
        }

        public function test_create_assistant_message_from_text_with_empty_string_returns_structure(): void
        {
                $result = create_assistant_message_from_text('');
                $this->assertEquals('assistant', $result['role']);
                $this->assertEquals('', $result['content'][0]['text']);
        }

        public function test_create_assistant_message_from_text_with_special_chars_returns_correct(): void
        {
                $text = 'Response "with" & <special>';
                $result = create_assistant_message_from_text($text);
                $this->assertEquals($text, $result['content'][0]['text']);
        }

        public function test_create_assistant_payload_from_text_returns_text_and_message(): void
        {
                $result = create_assistant_payload_from_text('Test text');
                $this->assertArrayHasKey('text', $result);
                $this->assertArrayHasKey('message', $result);
                $this->assertEquals('Test text', $result['text']);
                $this->assertEquals('assistant', $result['message']['role']);
        }

        public function test_build_display_text_from_parts_with_text_parts_returns_joined_text(): void
        {
                $parts = [
                        ['type' => 'text', 'text' => 'part1'],
                        ['type' => 'text', 'text' => 'part2']
                ];
                $result = build_display_text_from_parts($parts);
                $this->assertEquals("part1\npart2", $result);
        }

        public function test_build_display_text_from_parts_with_empty_array_returns_error_message(): void
        {
                $result = build_display_text_from_parts([]);
                $this->assertEquals(TECH_ERROR_MESSAGE, $result);
        }

        public function test_build_display_text_from_parts_with_non_text_parts_skips_them(): void
        {
                $parts = [
                        ['type' => 'text', 'text' => 'text1'],
                        ['type' => 'image', 'url' => 'http://example.com'],
                        ['type' => 'text', 'text' => 'text2']
                ];
                $result = build_display_text_from_parts($parts);
                $this->assertEquals("text1\ntext2", $result);
        }

        public function test_build_display_text_from_parts_with_empty_strings_skips_them(): void
        {
                $parts = [
                        ['type' => 'text', 'text' => 'text1'],
                        ['type' => 'text', 'text' => '   '],
                        ['type' => 'text', 'text' => 'text2']
                ];
                $result = build_display_text_from_parts($parts);
                $this->assertEquals("text1\ntext2", $result);
        }
}

