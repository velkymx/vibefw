<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\Mcp\McpProtocol;
use Fw\Aux\Mcp\McpServer;
use PHPUnit\Framework\TestCase;

final class McpServerTest extends TestCase
{
    public function testProcessLineReturnsJsonRpcResponse(): void
    {
        $registry = new \Fw\Aux\ToolRegistry();
        $protocol = new McpProtocol($registry);
        $server = new McpServer($protocol);

        $request = '{"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"protocolVersion": "2024-11-05"}}';
        $response = $server->processLine($request);

        $decoded = json_decode($response, true);

        $this->assertIsArray($decoded);
        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertEquals(1, $decoded['id']);
        $this->assertArrayHasKey('result', $decoded);
    }

    public function testProcessLineWithNewlineTerminator(): void
    {
        $registry = new \Fw\Aux\ToolRegistry();
        $protocol = new McpProtocol($registry);
        $server = new McpServer($protocol);

        $request = '{"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {}}' . "\n";
        $response = $server->processLine($request);

        $decoded = json_decode($response, true);

        $this->assertIsArray($decoded);
    }

    public function testProcessLineWithToolsList(): void
    {
        $registry = new \Fw\Aux\ToolRegistry();
        $registry->register(new \Fw\Aux\Tool(
            name: 'test_tool',
            description: 'Test tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => \Fw\Aux\WorkflowResult::success(),
        ));
        $protocol = new McpProtocol($registry);
        $server = new McpServer($protocol);

        $request = '{"jsonrpc": "2.0", "id": 2, "method": "tools/list", "params": {}}';
        $response = $server->processLine($request);

        $decoded = json_decode($response, true);

        $this->assertCount(1, $decoded['result']['tools']);
        $this->assertEquals('test_tool', $decoded['result']['tools'][0]['name']);
    }

    public function testProcessLineWithAbilitiesFiltersTools(): void
    {
        $registry = new \Fw\Aux\ToolRegistry();
        $registry->register(new \Fw\Aux\Tool(
            name: 'public_tool',
            description: 'Public tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => \Fw\Aux\WorkflowResult::success(),
            abilities: [],
        ));
        $registry->register(new \Fw\Aux\Tool(
            name: 'admin_tool',
            description: 'Admin tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => \Fw\Aux\WorkflowResult::success(),
            abilities: ['admin'],
        ));
        $protocol = new McpProtocol($registry);
        $server = new McpServer($protocol);

        $request = '{"jsonrpc": "2.0", "id": 2, "method": "tools/list", "params": {}}';
        $response = $server->processLine($request, ['read']);

        $decoded = json_decode($response, true);

        $this->assertCount(1, $decoded['result']['tools']);
        $this->assertEquals('public_tool', $decoded['result']['tools'][0]['name']);
    }

    public function testProcessLineEmptyInputReturnsEmpty(): void
    {
        $registry = new \Fw\Aux\ToolRegistry();
        $protocol = new McpProtocol($registry);
        $server = new McpServer($protocol);

        $response = $server->processLine('');

        $this->assertEmpty($response);
    }

    public function testProcessLineMalformedJsonReturnsError(): void
    {
        $registry = new \Fw\Aux\ToolRegistry();
        $protocol = new McpProtocol($registry);
        $server = new McpServer($protocol);

        $response = $server->processLine('not json');

        $decoded = json_decode($response, true);

        $this->assertArrayHasKey('error', $decoded);
        $this->assertEquals(-32700, $decoded['error']['code']);
    }

    public function testProcessLineNotificationReturnsEmpty(): void
    {
        $registry = new \Fw\Aux\ToolRegistry();
        $protocol = new McpProtocol($registry);
        $server = new McpServer($protocol);

        $request = '{"jsonrpc": "2.0", "method": "initialized", "params": {}}';
        $response = $server->processLine($request);

        $this->assertEmpty($response);
    }
}
