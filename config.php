<?php

use Dotenv\Dotenv;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Socket\Connector;
use React\Dns\Config\Config as DnsConfig;
use React\Dns\Resolver\Resolver;
use React\Dns\Resolver\Factory as DnsFactory;

const CLIENT_TIMEOUT = 60.0;
const CLIENT_CONNECT_TIMEOUT = 10.0;
const SEARCH_TIMEOUT = 10.0;
const SEARCH_CONNECT_TIMEOUT = 5.0;

const ALICE_TIMEOUT_LIMIT = 4.5;
const QUICK_RESPONSE_TIMEOUT = 4.2;
const MAX_WAIT_SECONDS = 30.0;
const WAITING_MESSAGE_FORMAT = 'Надо подумать. (прошло секунд %d). Через некоторое время, спросите: Готово?';
const TECH_ERROR_MESSAGE = 'Произошла внутренняя ошибка';

function load_config(): void
{
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                @session_start();
        }

        $dotenv = Dotenv::createImmutable(__DIR__);
        $dotenv->load();
}

function create_openrouter_react_client(?\React\EventLoop\LoopInterface $loop = null): Browser
{
        $apiKey = $_ENV['OPENROUTER_API_KEY'] ?? ($_ENV['GEMINI_API_KEY'] ?? '');
        if ($apiKey === '') {
                throw new \RuntimeException('API key is not configured.');
        }

        if ($loop === null) {
                $loop = Loop::get();
        }
        
        // Настройка DNS резолвера
        $dnsConfig = DnsConfig::loadSystemConfigBlocking();
        $dnsResolverFactory = new DnsFactory();
        $dnsResolver = $dnsResolverFactory->createCached($dnsConfig, $loop);

        // Настройка коннектора с таймаутами
        $connector = new Connector([
                'timeout' => CLIENT_CONNECT_TIMEOUT,
                'dns' => $dnsResolver,
                'tcp' => [
                        'bindto' => '0:0'
                ],
                'tls' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'peer_name' => 'openrouter.ai'
                ]
        ], $loop);

        // Создание HTTP клиента
        $browser = new Browser($connector, $loop);

            return $browser;
    }

    function create_google_search_client(?\React\EventLoop\LoopInterface $loop = null): Browser
    {
            if ($loop === null) {
                    $loop = Loop::get();
            }
            
            // Настройка DNS резолвера
            $dnsConfig = DnsConfig::loadSystemConfigBlocking();
            $dnsResolverFactory = new DnsFactory();
            $dnsResolver = $dnsResolverFactory->createCached($dnsConfig, $loop);

            // Настройка коннектора с таймаутами для Google Search
            $connector = new Connector([
                    'timeout' => SEARCH_CONNECT_TIMEOUT,
                    'dns' => $dnsResolver,
                    'tcp' => [
                            'bindto' => '0:0'
                    ],
                    'tls' => [
                            'verify_peer' => true,
                            'verify_peer_name' => true,
                            'peer_name' => 'www.googleapis.com' // Правильный peer_name для Google APIs
                    ]
            ], $loop);

            // Создание HTTP клиента
            $browser = new Browser($connector, $loop);

            return $browser;
    }

function get_openrouter_headers(): array
{
        $apiKey = $_ENV['OPENROUTER_API_KEY'] ?? ($_ENV['GEMINI_API_KEY'] ?? '');
        if ($apiKey === '') {
                throw new \RuntimeException('API key is not configured.');
        }

        $headers = [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $apiKey,
        ];

        if (!empty($_ENV['OPENROUTER_SITE_URL'])) {
                $headers['HTTP-Referer'] = $_ENV['OPENROUTER_SITE_URL'];
        }

        if (!empty($_ENV['OPENROUTER_APP_NAME'])) {
                $headers['X-Title'] = $_ENV['OPENROUTER_APP_NAME'];
        }

        return $headers;
}

function get_openrouter_base_uri(): string
{
        return 'https://openrouter.ai/api/v1/';
}
