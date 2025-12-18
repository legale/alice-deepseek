<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../config.php';

use GuzzleHttp\Client;

class ConfigTest extends \PHPUnit\Framework\TestCase
{
        protected function setUp(): void
        {
                $_ENV['OPENROUTER_API_KEY'] = 'test_key_123';
                unset($_ENV['GEMINI_API_KEY']);
                unset($_ENV['OPENROUTER_SITE_URL']);
                unset($_ENV['OPENROUTER_APP_NAME']);
        }

        protected function tearDown(): void
        {
                unset($_ENV['OPENROUTER_API_KEY']);
        }

        public function test_create_openrouter_client_with_valid_key_returns_client(): void
        {
                $client = create_openrouter_client();
                $this->assertInstanceOf(Client::class, $client);
        }

        public function test_create_openrouter_client_without_key_throws_exception(): void
        {
                unset($_ENV['OPENROUTER_API_KEY']);
                unset($_ENV['GEMINI_API_KEY']);

                $this->expectException(\RuntimeException::class);
                $this->expectExceptionMessage('API key is not configured');
                create_openrouter_client();
        }

        public function test_create_openrouter_client_with_gemini_key_uses_it(): void
        {
                unset($_ENV['OPENROUTER_API_KEY']);
                $_ENV['GEMINI_API_KEY'] = 'gemini_key_123';

                $client = create_openrouter_client();
                $this->assertInstanceOf(Client::class, $client);
        }

        public function test_create_openrouter_client_with_site_url_adds_header(): void
        {
                $_ENV['OPENROUTER_SITE_URL'] = 'https://example.com';
                $client = create_openrouter_client();
                $this->assertInstanceOf(Client::class, $client);
        }

        public function test_create_openrouter_client_with_app_name_adds_header(): void
        {
                $_ENV['OPENROUTER_APP_NAME'] = 'Test App';
                $client = create_openrouter_client();
                $this->assertInstanceOf(Client::class, $client);
        }
}

