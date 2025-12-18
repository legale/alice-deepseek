<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../model_manager.php';


class ModelManagerTest extends \PHPUnit\Framework\TestCase
{
        private string $tempDir;

        protected function setUp(): void
        {
                $this->tempDir = sys_get_temp_dir() . '/alice_test_' . uniqid();
                mkdir($this->tempDir, 0777, true);
        }

        protected function tearDown(): void
        {
                if (is_dir($this->tempDir)) {
                        array_map('unlink', glob($this->tempDir . '/*'));
                        rmdir($this->tempDir);
                }
        }

        public function test_display_model_name_with_free_suffix_removes_it(): void
        {
                $result = display_model_name('model:free');
                $this->assertEquals('model', $result);
        }

        public function test_display_model_name_without_free_returns_unchanged(): void
        {
                $result = display_model_name('model');
                $this->assertEquals('model', $result);
        }

        public function test_display_model_name_with_empty_string_returns_empty(): void
        {
                $result = display_model_name('');
                $this->assertEquals('', $result);
        }

        public function test_display_model_name_with_only_free_returns_free(): void
        {
                $result = display_model_name(':free');
                $this->assertEquals(':free', $result);
        }

        public function test_load_model_list_with_valid_file_returns_models(): void
        {
                $file = $this->tempDir . '/models.txt';
                file_put_contents($file, "model1 1000\nmodel2 2000\nmodel3 3000");

                $result = load_model_list($file);
                $this->assertCount(3, $result);
                $this->assertArrayHasKey('model1', $result);
                $this->assertEquals([0 => 'model1', 1 => 1000], $result['model1']);
        }

        public function test_load_model_list_with_nonexistent_file_returns_empty(): void
        {
                $result = load_model_list($this->tempDir . '/nonexistent.txt');
                $this->assertEquals([], $result);
        }

        public function test_load_model_list_with_empty_file_returns_empty(): void
        {
                $file = $this->tempDir . '/empty.txt';
                touch($file);

                $result = load_model_list($file);
                $this->assertEquals([], $result);
        }

        public function test_load_model_list_with_invalid_lines_skips_them(): void
        {
                $file = $this->tempDir . '/models.txt';
                file_put_contents($file, "model1 1000\ninvalid\nmodel2 2000\n   \nmodel3 3000");

                $result = load_model_list($file);
                $this->assertCount(3, $result);
                $this->assertArrayHasKey('model1', $result);
                $this->assertArrayHasKey('model2', $result);
                $this->assertArrayHasKey('model3', $result);
        }

        public function test_load_model_list_with_spaces_handles_correctly(): void
        {
                $file = $this->tempDir . '/models.txt';
                file_put_contents($file, "  model1  1000  \nmodel2\t2000");

                $result = load_model_list($file);
                $this->assertCount(2, $result);
                $this->assertEquals(1000, $result['model1'][1]);
                $this->assertEquals(2000, $result['model2'][1]);
        }

        public function test_load_model_state_with_valid_json_returns_model(): void
        {
                $file = $this->tempDir . '/state.json';
                file_put_contents($file, json_encode(['current_model' => 'test/model']));

                $result = load_model_state($file);
                $this->assertEquals('test/model', $result);
        }

        public function test_load_model_state_with_nonexistent_file_returns_null(): void
        {
                $result = load_model_state($this->tempDir . '/nonexistent.json');
                $this->assertNull($result);
        }

        public function test_load_model_state_with_empty_file_returns_null(): void
        {
                $file = $this->tempDir . '/empty.json';
                touch($file);

                $result = load_model_state($file);
                $this->assertNull($result);
        }

        public function test_load_model_state_with_invalid_json_returns_fallback(): void
        {
                $file = $this->tempDir . '/invalid.json';
                file_put_contents($file, '{invalid json}');

                $result = load_model_state($file);
                $this->assertEquals('{invalid json}', $result);
        }

        public function test_load_model_state_with_simple_string_returns_string(): void
        {
                $file = $this->tempDir . '/string.txt';
                file_put_contents($file, 'simple_model');

                $result = load_model_state($file);
                $this->assertEquals('simple_model', $result);
        }

        public function test_persist_model_state_with_valid_model_saves_file(): void
        {
                $file = $this->tempDir . '/state.json';
                persist_model_state('test/model', $file);

                $this->assertFileExists($file);
                $content = json_decode(file_get_contents($file), true);
                $this->assertEquals('test/model', $content['current_model']);
        }

        public function test_persist_model_state_with_empty_path_does_nothing(): void
        {
                persist_model_state('test/model', '');
                $this->assertTrue(true);
        }

        public function test_sync_model_state_with_stored_model_sets_it(): void
        {
                $file = $this->tempDir . '/state.json';
                file_put_contents($file, json_encode(['current_model' => 'model2']));

                $modelList = ['model1' => ['model1', 1000], 'model2' => ['model2', 2000]];
                $modelId = 'model1';
                sync_model_state($modelList, $modelId, $file);

                $this->assertEquals('model2', $modelId);
        }

        public function test_sync_model_state_with_nonexistent_stored_model_uses_first(): void
        {
                $file = $this->tempDir . '/state.json';
                file_put_contents($file, json_encode(['current_model' => 'nonexistent']));

                $modelList = ['model1' => ['model1', 1000], 'model2' => ['model2', 2000]];
                $modelId = 'model1';
                sync_model_state($modelList, $modelId, $file);

                $this->assertEquals('model1', $modelId);
        }

        public function test_sync_model_state_with_empty_list_does_nothing(): void
        {
                $file = $this->tempDir . '/state.json';
                $modelList = [];
                $modelId = 'model1';
                sync_model_state($modelList, $modelId, $file);

                $this->assertEquals('model1', $modelId);
        }

        public function test_sync_model_state_with_model_not_in_list_uses_first(): void
        {
                $file = $this->tempDir . '/state.json';
                $modelList = ['model1' => ['model1', 1000]];
                $modelId = 'nonexistent';
                sync_model_state($modelList, $modelId, $file);

                $this->assertEquals('model1', $modelId);
        }

        public function test_switch_to_next_model_switches_to_next(): void
        {
                $file = $this->tempDir . '/state.json';
                $modelList = [
                        'model1' => ['model1', 1000],
                        'model2' => ['model2', 2000],
                        'model3' => ['model3', 3000]
                ];
                $modelId = 'model1';
                $maxTokens = 1000;

                switch_to_next_model($modelList, $modelId, $maxTokens, $file);

                $this->assertEquals('model2', $modelId);
                $this->assertEquals(2000, $maxTokens);
        }

        public function test_switch_to_next_model_wraps_around(): void
        {
                $file = $this->tempDir . '/state.json';
                $modelList = [
                        'model1' => ['model1', 1000],
                        'model2' => ['model2', 2000]
                ];
                $modelId = 'model2';
                $maxTokens = 2000;

                switch_to_next_model($modelList, $modelId, $maxTokens, $file);

                $this->assertEquals('model1', $modelId);
                $this->assertEquals(1000, $maxTokens);
        }

        public function test_switch_to_next_model_with_empty_list_returns_unchanged(): void
        {
                $file = $this->tempDir . '/state.json';
                $modelList = [];
                $modelId = 'model1';
                $maxTokens = 1000;

                $result = switch_to_next_model($modelList, $modelId, $maxTokens, $file);

                $this->assertEquals('model1', $result);
                $this->assertEquals('model1', $modelId);
        }

        public function test_switch_to_next_model_with_model_not_in_list_uses_first(): void
        {
                $file = $this->tempDir . '/state.json';
                $modelList = [
                        'model1' => ['model1', 1000],
                        'model2' => ['model2', 2000]
                ];
                $modelId = 'nonexistent';
                $maxTokens = 0;

                switch_to_next_model($modelList, $modelId, $maxTokens, $file);

                $this->assertEquals('model1', $modelId);
                $this->assertEquals(1000, $maxTokens);
        }
}

