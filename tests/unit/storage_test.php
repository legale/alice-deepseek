<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../storage.php';


class StorageTest extends \PHPUnit\Framework\TestCase
{
        public function test_sanitize_session_id_with_valid_chars_returns_unchanged(): void
        {
                $result = sanitize_session_id('abc123ABC-_');
                $this->assertEquals('abc123ABC-_', $result);
        }

        public function test_sanitize_session_id_with_invalid_chars_replaces_them(): void
        {
                $result = sanitize_session_id('test@session#id');
                $this->assertEquals('test_session_id', $result);
        }

        public function test_sanitize_session_id_with_empty_string_returns_session(): void
        {
                $result = sanitize_session_id('');
                $this->assertEquals('session', $result);
        }

        public function test_sanitize_session_id_with_only_invalid_chars_returns_underscores(): void
        {
                $result = sanitize_session_id('@#$%');
                $this->assertEquals('____', $result);
        }

        public function test_ends_with_when_string_ends_with_needle_returns_true(): void
        {
                $result = ends_with('test.json', '.json');
                $this->assertTrue($result);
        }

        public function test_ends_with_when_string_not_ends_with_needle_returns_false(): void
        {
                $result = ends_with('test.json.gz', '.json');
                $this->assertFalse($result);
        }

        public function test_ends_with_with_empty_needle_returns_true(): void
        {
                $result = ends_with('test', '');
                $this->assertTrue($result);
        }

        public function test_ends_with_with_empty_haystack_returns_true(): void
        {
                $result = ends_with('', 'needle');
                $this->assertTrue($result);
        }

        public function test_is_legacy_json_path_with_json_returns_true(): void
        {
                $result = is_legacy_json_path('/path/to/file.json');
                $this->assertTrue($result);
        }

        public function test_is_legacy_json_path_with_json_gz_returns_false(): void
        {
                $result = is_legacy_json_path('/path/to/file.json.gz');
                $this->assertFalse($result);
        }

        public function test_is_legacy_json_path_without_extension_returns_false(): void
        {
                $result = is_legacy_json_path('/path/to/file');
                $this->assertFalse($result);
        }

        public function test_build_timestamped_file_path_with_base_time_uses_it(): void
        {
                $baseTime = 1609459200;
                $result = build_timestamped_file_path('/tmp', 'session123', $baseTime);
                $this->assertStringContainsString('20210101_000000', $result);
                $this->assertStringContainsString('session123', $result);
                $this->assertStringEndsWith('.json.gz', $result);
        }

        public function test_build_timestamped_file_path_without_base_time_uses_current(): void
        {
                $result = build_timestamped_file_path('/tmp', 'session123');
                $this->assertStringContainsString('session123', $result);
                $this->assertStringEndsWith('.json.gz', $result);
                $this->assertRegExp('/\d{8}_\d{6}/', $result);
        }

        public function test_build_timestamped_file_path_sanitizes_session_id(): void
        {
                $result = build_timestamped_file_path('/tmp', 'session@123');
                $this->assertStringContainsString('session_123', $result);
                $this->assertStringNotContainsString('@', $result);
        }
}

