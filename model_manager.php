<?php

function load_model_list(string $path): array
{
        if (!is_file($path)) {
                return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
                return [];
        }

        $models = [];
        foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                        continue;
                }
                
                $parts = preg_split('/\s+/', $line, 2);
                if (count($parts) < 2) {
                        continue;
                }
                
                $name = trim($parts[0]);
                $max_tokens = (int)trim($parts[1]);
                if ($name !== '') {
                        $models[$name] = [$name, $max_tokens];
                }
        }

        return $models;
}

function load_model_state(string $modelStatePath): ?string
{
        if ($modelStatePath === '' || !is_file($modelStatePath)) {
                return null;
        }

        $contents = file_get_contents($modelStatePath);
        if ($contents === false || trim($contents) === '') {
                return null;
        }

        $data = json_decode($contents, true);
        if (is_array($data) && isset($data['current_model']) && is_string($data['current_model'])) {
                return $data['current_model'];
        }

        $fallback = trim($contents);
        return $fallback !== '' ? $fallback : null;
}

function persist_model_state(string $model, string $modelStatePath): void
{
        if ($modelStatePath === '') {
                return;
        }

        $payload = json_encode(['current_model' => $model], JSON_UNESCAPED_UNICODE);
        file_put_contents($modelStatePath, $payload, LOCK_EX);
}

function sync_model_state(array &$modelList, string &$modelId, string $modelStatePath): void
{
        if (empty($modelList)) {
                return;
        }

        $storedModel = load_model_state($modelStatePath);
        if ($storedModel !== null && isset($modelList[$storedModel])) {
                $modelId = $storedModel;
        } elseif (!isset($modelList[$modelId])) {
                $modelId = array_key_first($modelList);
        }

        if (!isset($modelList[$modelId])) {
                $modelId = array_key_first($modelList);
        }

        persist_model_state($modelId, $modelStatePath);
}

function switch_to_next_model(array $modelList, string &$modelId, int &$maxTokens, string $modelStatePath): string
{
        if (empty($modelList)) {
                return $modelId;
        }

        $keys = array_keys($modelList);
        
        $currentKey = array_search($modelId, $keys, true);
        
        if ($currentKey === false) {
                $currentKey = 0;
        } else {
                $currentKey = ($currentKey + 1) % count($keys);
        }
        
        $nextModelId = $keys[$currentKey];
        $model = $modelList[$nextModelId];
        
        $modelId = $model[0];
        $maxTokens = $model[1];
        persist_model_state($modelId, $modelStatePath);
        
        return $modelId;
}

function display_model_name(string $modelId): string
{
        $clean = preg_replace('/:free$/i', '', $modelId);
        return $clean !== '' ? $clean : $modelId;
}

