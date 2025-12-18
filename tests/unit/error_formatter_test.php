<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../error_formatter.php';

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

class ErrorFormatterTest extends \PHPUnit\Framework\TestCase
{
        public function test_format_code_with_null_returns_na(): void
        {
                $result = format_code(null);
                $this->assertEquals('N/A', $result);
        }

        public function test_format_code_with_empty_string_returns_na(): void
        {
                $result = format_code('');
                $this->assertEquals('N/A', $result);
        }

        public function test_format_code_with_number_returns_string(): void
        {
                $result = format_code(404);
                $this->assertEquals('404', $result);
        }

        public function test_format_code_with_string_number_returns_string(): void
        {
                $result = format_code('500');
                $this->assertEquals('500', $result);
        }

        public function test_format_code_with_special_chars_returns_string(): void
        {
                $result = format_code('ERR-123');
                $this->assertEquals('ERR-123', $result);
        }

        public function test_extract_error_text_with_empty_string_returns_empty(): void
        {
                $result = extract_error_text('');
                $this->assertEquals('', $result);
        }

        public function test_extract_error_text_with_spaces_returns_trimmed(): void
        {
                $result = extract_error_text('  error message  ');
                $this->assertEquals('error message', $result);
        }

        public function test_extract_error_text_with_multiline_returns_trimmed(): void
        {
                $result = extract_error_text("line1\nline2\n");
                $this->assertEquals("line1\nline2", $result);
        }

        public function test_extract_error_text_with_json_returns_trimmed(): void
        {
                $json = '{"error": "message"}';
                $result = extract_error_text($json);
                $this->assertEquals($json, $result);
        }

        public function test_get_curl_errno_with_exception_with_errno_returns_errno(): void
        {
                $exception = $this->createMock(ConnectException::class);
                $exception->method('getHandlerContext')
                        ->willReturn(['errno' => 28]);

                $result = get_curl_errno($exception);
                $this->assertEquals(28, $result);
        }

        public function test_get_curl_errno_with_exception_without_errno_returns_null(): void
        {
                $exception = $this->createMock(ConnectException::class);
                $exception->method('getHandlerContext')
                        ->willReturn([]);

                $result = get_curl_errno($exception);
                $this->assertNull($result);
        }

        public function test_get_curl_errno_with_exception_with_invalid_errno_returns_null(): void
        {
                $exception = $this->createMock(ConnectException::class);
                $exception->method('getHandlerContext')
                        ->willReturn(['errno' => 'invalid']);

                $result = get_curl_errno($exception);
                $this->assertNull($result);
        }

        public function test_is_timeout_errno_with_valid_timeout_code_returns_true(): void
        {
                $timeoutCode = defined('CURLE_OPERATION_TIMEDOUT') ? CURLE_OPERATION_TIMEDOUT : 28;
                $result = is_timeout_errno($timeoutCode);
                $this->assertTrue($result);
        }

        public function test_is_timeout_errno_with_non_timeout_code_returns_false(): void
        {
                $result = is_timeout_errno(404);
                $this->assertFalse($result);
        }

        public function test_is_timeout_errno_with_null_returns_false(): void
        {
                $result = is_timeout_errno(null);
                $this->assertFalse($result);
        }

        public function test_is_timeout_exception_with_timeout_errno_returns_true(): void
        {
                $exception = $this->createMock(RequestException::class);
                $exception->method('getHandlerContext')
                        ->willReturn(['errno' => defined('CURLE_OPERATION_TIMEDOUT') ? CURLE_OPERATION_TIMEDOUT : 28]);

                $result = is_timeout_exception($exception);
                $this->assertTrue($result);
        }

        public function test_is_timeout_exception_without_timeout_errno_returns_false(): void
        {
                $exception = $this->createMock(RequestException::class);
                $exception->method('getHandlerContext')
                        ->willReturn(['errno' => 404]);

                $result = is_timeout_exception($exception);
                $this->assertFalse($result);
        }

        public function test_is_timeout_exception_without_errno_returns_false(): void
        {
                $exception = $this->createMock(RequestException::class);
                $exception->method('getHandlerContext')
                        ->willReturn([]);

                $result = is_timeout_exception($exception);
                $this->assertFalse($result);
        }

        public function test_format_request_error_with_response_and_code_returns_formatted(): void
        {
                $response = $this->createMock(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn(404);
                $response->method('getBody')->willReturn('Not Found');

                $exception = $this->createMock(RequestException::class);
                $exception->method('hasResponse')->willReturn(true);
                $exception->method('getResponse')->willReturn($response);
                $exception->method('getMessage')->willReturn('');

                $result = format_request_error($exception);
                $this->assertStringContainsString('Ошибка OpenRouter код=404', $result);
                $this->assertStringContainsString('Not Found', $result);
        }

        public function test_format_request_error_without_response_with_errno_returns_formatted(): void
        {
                $exception = $this->createMock(RequestException::class);
                $exception->method('hasResponse')->willReturn(false);
                $exception->method('getHandlerContext')
                        ->willReturn(['errno' => 28]);
                $exception->method('getMessage')->willReturn('Connection failed');

                $result = format_request_error($exception);
                $this->assertStringContainsString('Ошибка OpenRouter код=28', $result);
                $this->assertStringContainsString('Connection failed', $result);
        }

        public function test_format_request_error_without_response_without_errno_returns_formatted(): void
        {
                $exception = $this->createMock(RequestException::class);
                $exception->method('hasResponse')->willReturn(false);
                $exception->method('getHandlerContext')->willReturn([]);
                $exception->method('getMessage')->willReturn('Error message');

                $result = format_request_error($exception);
                $this->assertStringContainsString('Ошибка OpenRouter', $result);
                $this->assertStringContainsString('Error message', $result);
        }

        public function test_format_request_error_with_empty_message_returns_code_only(): void
        {
                $exception = $this->createMock(RequestException::class);
                $exception->method('hasResponse')->willReturn(false);
                $exception->method('getHandlerContext')
                        ->willReturn(['errno' => 28]);
                $exception->method('getMessage')->willReturn('');

                $result = format_request_error($exception);
                $this->assertEquals('Ошибка OpenRouter код=28', $result);
        }

        public function test_format_connect_error_with_errno_and_message_returns_formatted(): void
        {
                $exception = $this->createMock(ConnectException::class);
                $exception->method('getHandlerContext')
                        ->willReturn(['errno' => 7]);
                $exception->method('getMessage')->willReturn('Connection refused');

                $result = format_connect_error($exception);
                $this->assertStringContainsString('Ошибка соединения с OpenRouter код=7', $result);
                $this->assertStringContainsString('Connection refused', $result);
        }

        public function test_format_connect_error_without_errno_returns_formatted(): void
        {
                $exception = $this->createMock(ConnectException::class);
                $exception->method('getHandlerContext')->willReturn([]);
                $exception->method('getMessage')->willReturn('Connection failed');

                $result = format_connect_error($exception);
                $this->assertStringContainsString('Ошибка соединения с OpenRouter', $result);
                $this->assertStringContainsString('Connection failed', $result);
        }

        public function test_format_connect_error_with_empty_message_returns_code_only(): void
        {
                $exception = $this->createMock(ConnectException::class);
                $exception->method('getHandlerContext')
                        ->willReturn(['errno' => 7]);
                $exception->method('getMessage')->willReturn('');

                $result = format_connect_error($exception);
                $this->assertEquals('Ошибка соединения с OpenRouter код=7', $result);
        }

        public function test_format_generic_error_with_code_and_message_returns_formatted(): void
        {
                $exception = new \RuntimeException('Error message', 500);
                $result = format_generic_error($exception);
                $this->assertStringContainsString('Внутренняя ошибка код=500', $result);
                $this->assertStringContainsString('Error message', $result);
        }

        public function test_format_generic_error_without_code_returns_formatted(): void
        {
                $exception = new \RuntimeException('Error message', 0);
                $result = format_generic_error($exception);
                $this->assertStringContainsString('Внутренняя ошибка', $result);
                $this->assertStringContainsString('Error message', $result);
        }

        public function test_format_generic_error_with_empty_message_returns_code_only(): void
        {
                $exception = new \RuntimeException('', 500);
                $result = format_generic_error($exception);
                $this->assertEquals('Внутренняя ошибка код=500', $result);
        }

        public function test_format_generic_error_with_zero_code_returns_formatted(): void
        {
                $exception = new \RuntimeException('Error message', 0);
                $result = format_generic_error($exception);
                $this->assertStringContainsString('Внутренняя ошибка код=N/A', $result);
                $this->assertStringContainsString('Error message', $result);
        }
}

