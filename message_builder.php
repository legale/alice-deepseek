<?php

const SYSTEM_PROMPT = 'Ты голосовой ассистент, отвечай коротко по существу, без эмодзи. 
    Не используй в ответах специальных разделителей, только простой текст. Длинный ответы дели на 
    части. Отправляй продолжение, когда просят: дальше или продолжи.
    У тебя есть доступ к функции поиска в интернете через Google Custom Search. 
    Если тебе нужна актуальная информация из интернета или пользователь просит найти что-то, 
    используй функцию search_internet. Важно: поиск выполняется только после подтверждения 
    пользователем. Сформулируй поисковый запрос максимально точно и информативно.';

const TECH_ERROR_MESSAGE = 'Произошла техническая ошибка. Пожалуйста, попробуйте позже.';

function clean_input(string $input): string
{
        $patterns = ['/^алиса,?\s*/ui', '/^аиса,?\s*/ui'];
        return trim(preg_replace($patterns, '', $input));
}

function normalize_content_parts($content): array
{
        if (is_string($content)) {
                return [
                        [
                                'type' => 'text',
                                'text' => $content
                        ]
                ];
        }

        $parts = [];
        foreach ((array) $content as $part) {
                if (is_string($part)) {
                        $parts[] = [
                                'type' => 'text',
                                'text' => $part
                        ];
                        continue;
                }

                if (!is_array($part)) {
                        continue;
                }

                $type = $part['type'] ?? 'text';
                if ($type === 'text') {
                        $parts[] = [
                                'type' => 'text',
                                'text' => (string) ($part['text'] ?? '')
                        ];
                } else {
                        $parts[] = $part;
                }
        }

        if (empty($parts)) {
                $parts[] = [
                        'type' => 'text',
                        'text' => TECH_ERROR_MESSAGE
                ];
        }

        return $parts;
}

function build_messages(array $history): array
{
        if (empty($history)) {
                $history = [];
        }

        $messages = [
                [
                        'role' => 'system',
                        'content' => [
                                [
                                        'type' => 'text',
                                        'text' => SYSTEM_PROMPT
                                ]
                        ]
                ]
        ];

        foreach ($history as $entry) {
                if (!is_array($entry) || empty($entry['role'])) {
                        continue;
                }

                $role = $entry['role'];
                
                if ($role === 'tool') {
                        $message = [
                                'role' => 'tool',
                                'tool_call_id' => $entry['tool_call_id'] ?? '',
                                'content' => $entry['content'] ?? ''
                        ];
                        if (is_array($message['content'])) {
                                $message['content'] = json_encode($message['content'], JSON_UNESCAPED_UNICODE);
                        }
                        $messages[] = $message;
                } else {
                        $message = [
                                'role' => $role,
                                'content' => normalize_content_parts($entry['content'] ?? [])
                        ];
                        
                        if (!empty($entry['tool_calls']) && is_array($entry['tool_calls'])) {
                                $message['tool_calls'] = $entry['tool_calls'];
                        }
                        
                        $messages[] = $message;
                }
        }

        return $messages;
}

function create_user_message(string $text): array
{
        return [
                'role' => 'user',
                'content' => [
                        [
                                'type' => 'text',
                                'text' => $text
                        ]
                ]
        ];
}

function create_assistant_message_from_text(string $text): array
{
        return [
                'role' => 'assistant',
                'content' => [
                        [
                                'type' => 'text',
                                'text' => $text
                        ]
                ]
        ];
}

function create_assistant_payload_from_text(string $text): array
{
        return [
                'text' => $text,
                'message' => create_assistant_message_from_text($text)
        ];
}

function build_display_text_from_parts(array $parts): string
{
        $texts = [];
        foreach ($parts as $part) {
                if (($part['type'] ?? 'text') === 'text' && isset($part['text'])) {
                        $value = trim((string) $part['text']);
                        if ($value !== '') {
                                $texts[] = $value;
                        }
                }
        }

        $text = trim(implode("\n", $texts));
        return $text !== '' ? $text : TECH_ERROR_MESSAGE;
}

