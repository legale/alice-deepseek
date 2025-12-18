<?php

require_once 'message_builder.php';

function sanitize_session_id(string $sessionId): string
{
        $safeChars = [];
        $allowed = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_';

        foreach (str_split($sessionId) as $char) {
                $safeChars[] = (strpos($allowed, $char) !== false) ? $char : '_';
        }

        $sanitized = implode('', $safeChars);
        return $sanitized !== '' ? $sanitized : 'session';
}

function is_legacy_json_path(string $path): bool
{
        return ends_with($path, '.json');
}

function ends_with(string $haystack, string $needle): bool
{
        if ($needle === '') {
                return true;
        }

        $length = strlen($needle);
        if ($length === 0) {
                return true;
        }

        if (strlen($haystack) < $length) {
                return false;
        }

        return substr($haystack, -$length) === $needle;
}

function get_file_timestamp(string $path): int
{
        $timestamp = @filemtime($path);
        return $timestamp !== false ? $timestamp : time();
}

function build_timestamped_file_path(string $directory, string $sessionId, ?int $baseTime = null): string
{
        $safeId = sanitize_session_id($sessionId);
        $timestamp = gmdate('Ymd_His', $baseTime ?? time());
        return sprintf('%s/%s_%s.json.gz', $directory, $timestamp, $safeId);
}

function read_compressed_json(string $path)
{
        $contents = @file_get_contents($path);
        if ($contents === false) {
                return null;
        }

        $decoded = @gzdecode($contents);
        $json = $decoded !== false ? $decoded : $contents;

        return json_decode($json, true);
}

function write_compressed_json(string $path, array $payload): void
{
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
                return;
        }

        $encoded = gzencode($json, 5);
        $data = $encoded !== false ? $encoded : $json;
        file_put_contents($path, $data, LOCK_EX);
}

function get_session_file_path(string $directory, string $sessionId, bool $create): ?string
{
        $safeId = sanitize_session_id($sessionId);
        $pattern = sprintf('%s/*_%s.json.gz', $directory, $safeId);
        $files = glob($pattern) ?: [];

        if (!empty($files)) {
                usort($files, static function ($a, $b) {
                        $timeA = @filemtime($a) ?: 0;
                        $timeB = @filemtime($b) ?: 0;
                        return $timeB <=> $timeA;
                });

                return $files[0];
        }

        $legacyPath = sprintf('%s/%s.json', $directory, $safeId);
        if (is_file($legacyPath)) {
                return $legacyPath;
        }

        return $create ? build_timestamped_file_path($directory, $sessionId) : null;
}

function delete_session_files(string $directory, string $sessionId): void
{
        $safeId = sanitize_session_id($sessionId);
        $patterns = [
                sprintf('%s/*_%s.json.gz', $directory, $safeId),
                sprintf('%s/%s.json', $directory, $safeId)
        ];

        foreach ($patterns as $pattern) {
                $files = glob($pattern) ?: [];
                foreach ($files as $file) {
                        @unlink($file);
                }
        }
}

function enforce_storage_limits(string $directory): void
{
        $files = glob($directory . '/*.json.gz') ?: [];
        if (count($files) <= 100) {
                return;
        }

        $threshold = time() - (4 * 3600);
        foreach ($files as $file) {
                $mtime = @filemtime($file);
                if ($mtime !== false && $mtime < $threshold) {
                        @unlink($file);
                }
        }
}

function load_conversation(string $sessionId, string $conversationDir): array
{
        $path = get_conversation_file_path($sessionId, $conversationDir);
        if ($path === null || !is_file($path)) {
                return [];
        }

        $data = read_compressed_json($path);
        if (!is_array($data)) {
                return [];
        }

        $history = [];
        foreach ($data as $entry) {
                if (is_array($entry) && isset($entry['role'])) {
                        $normalized = [
                                'role' => $entry['role'],
                                'content' => normalize_content_parts($entry['content'] ?? [])
                        ];
                        $history[] = $normalized;
                } elseif (is_string($entry)) {
                        $history[] = create_user_message($entry);
                }
        }

        return $history;
}

function save_conversation(string $sessionId, array $history, string $conversationDir): void
{
        $path = get_conversation_file_path($sessionId, $conversationDir, true);
        if ($path === null) {
                return;
        }

        $legacyPath = is_legacy_json_path($path) ? $path : null;
        if ($legacyPath !== null) {
                $path = build_timestamped_file_path($conversationDir, $sessionId, get_file_timestamp($legacyPath));
        }

        write_compressed_json($path, array_values($history));

        if ($legacyPath !== null && is_file($legacyPath)) {
                @unlink($legacyPath);
        }

        enforce_storage_limits($conversationDir);
}

function clear_conversation(string $sessionId, string $conversationDir): void
{
        delete_session_files($conversationDir, $sessionId);
}

function get_conversation_file_path(string $sessionId, string $conversationDir, bool $create = false): ?string
{
        return get_session_file_path($conversationDir, $sessionId, $create);
}

function load_pending_state(string $sessionId, string $pendingDir): ?array
{
        $path = get_pending_file_path($sessionId, $pendingDir);
        if ($path === null || !is_file($path)) {
                return null;
        }

        $data = read_compressed_json($path);
        return is_array($data) ? $data : null;
}

function save_pending_state(string $sessionId, array $state, string $pendingDir): void
{
        $path = get_pending_file_path($sessionId, $pendingDir, true);
        if ($path === null) {
                return;
        }

        $legacyPath = is_legacy_json_path($path) ? $path : null;
        if ($legacyPath !== null) {
                $path = build_timestamped_file_path($pendingDir, $sessionId, get_file_timestamp($legacyPath));
        }

        write_compressed_json($path, $state);

        if ($legacyPath !== null && is_file($legacyPath)) {
                @unlink($legacyPath);
        }

        enforce_storage_limits($pendingDir);
}

function clear_pending_state(string $sessionId, string $pendingDir): void
{
        delete_session_files($pendingDir, $sessionId);
}

function get_pending_file_path(string $sessionId, string $pendingDir, bool $create = false): ?string
{
        return get_session_file_path($pendingDir, $sessionId, $create);
}

