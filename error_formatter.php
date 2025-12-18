<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

function get_curl_errno($exception): ?int
{
        $context = $exception->getHandlerContext();
        return isset($context['errno']) ? (int) $context['errno'] : null;
}

function is_timeout_errno(?int $errno): bool
{
        $timeoutCode = defined('CURLE_OPERATION_TIMEDOUT') ? CURLE_OPERATION_TIMEDOUT : 28;
        return $errno !== null && $errno === $timeoutCode;
}

function is_timeout_exception(RequestException $exception): bool
{
        $errno = get_curl_errno($exception);
        return is_timeout_errno($errno);
}

function format_code($code): string
{
        if ($code === null || $code === '') {
                return 'N/A';
        }

        return (string) $code;
}

function extract_error_text(string $body): string
{
        return trim($body);
}

function format_request_error(RequestException $exception): string
{
        $code = null;
        $details = trim($exception->getMessage());

        if ($exception->hasResponse()) {
                $response = $exception->getResponse();
                $code = $response->getStatusCode();
                $details = extract_error_text((string) $response->getBody());
        } else {
                $errno = get_curl_errno($exception);
                if ($errno !== null) {
                        $code = $errno;
                }
        }

        if ($details === '') {
                return $code === null ? '' : sprintf('Ошибка OpenRouter код=%s', format_code($code));
        }

        $codeLabel = format_code($code ?? 'N/A');
        return sprintf('Ошибка OpenRouter код=%s %s', $codeLabel, $details);
}

function format_connect_error(ConnectException $exception): string
{
        $details = trim($exception->getMessage());
        $errno = get_curl_errno($exception);

        if ($details === '') {
                return $errno === null
                        ? ''
                        : sprintf('Ошибка соединения с OpenRouter код=%s', format_code($errno));
        }

        return sprintf(
                'Ошибка соединения с OpenRouter код=%s %s',
                format_code($errno ?? 'N/A'),
                $details
        );
}

function format_generic_error(\Throwable $exception): string
{
        $code = $exception->getCode();
        $details = trim($exception->getMessage());

        if ($details === '') {
                return $code ? sprintf('Внутренняя ошибка код=%s', format_code($code)) : '';
        }

        return sprintf('Внутренняя ошибка код=%s %s', format_code($code ?: 'N/A'), $details);
}

