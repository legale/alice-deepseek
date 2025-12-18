<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../tool_handler.php';


class ToolHandlerTest extends \PHPUnit\Framework\TestCase
{
        public function test_build_tools_definition_returns_array(): void
        {
                $result = build_tools_definition();
                $this->assertIsArray($result);
                $this->assertCount(1, $result);
        }

        public function test_build_tools_definition_has_search_internet(): void
        {
                $result = build_tools_definition();
                $this->assertEquals('function', $result[0]['type']);
                $this->assertEquals('search_internet', $result[0]['function']['name']);
        }

        public function test_build_tools_definition_has_correct_parameters(): void
        {
                $result = build_tools_definition();
                $params = $result[0]['function']['parameters'];
                $this->assertEquals('object', $params['type']);
                $this->assertArrayHasKey('properties', $params);
                $this->assertArrayHasKey('query', $params['properties']);
                $this->assertEquals('string', $params['properties']['query']['type']);
                $this->assertContains('query', $params['required']);
        }

        public function test_build_tools_definition_has_description(): void
        {
                $result = build_tools_definition();
                $this->assertArrayHasKey('description', $result[0]['function']);
                $this->assertNotEmpty($result[0]['function']['description']);
        }
}

