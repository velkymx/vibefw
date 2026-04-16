<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\Mcp\McpProtocol;
use Fw\Aux\Tool;
use Fw\Aux\ToolRegistry;
use Fw\Aux\WorkflowResult;
use PHPUnit\Framework\TestCase;

final class McpProtocolTest extends TestCase
{
    private ToolRegistry $registry;
    private McpProtocol $protocol;

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry();
        $this->protocol = new McpProtocol($this->registry);
    }

    public function testInitializeReturnsCorrectResponse(): void
    {
        $request = '{"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"protocolVersion": "2024-11-05", "clientInfo": {"name": "claude-desktop", "version": "1.0"}}}';

        $response = $this->protocol->handle($request);

        $decoded = json_decode($response, true);

        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertEquals(1, $decoded['id']);
        $this->assertArrayHasKey('result', $decoded);
        $this->assertEquals('2024-11-05', $decoded['result']['protocolVersion']);
        $this->assertEquals('VibeFW AUX', $decoded['result']['serverInfo']['name']);
    }

    public function testToolsListReturnsAllTools(): void
    {
        $this->registry->register(new Tool(
            name: 'tool_one',
            description: 'First tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => WorkflowResult::success(),
        ));
        $this->registry->register(new Tool(
            name: 'tool_two',
            description: 'Second tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => WorkflowResult::success(),
        ));

        $request = '{"jsonrpc": "2.0", "id": 2, "method": "tools/list", "params": {}}';

        $response = $this->protocol->handle($request);

        $decoded = json_decode($response, true);

        $this->assertCount(2, $decoded['result']['tools']);
        $this->assertEquals('tool_one', $decoded['result']['tools'][0]['name']);
        $this->assertEquals('tool_two', $decoded['result']['tools'][1]['name']);
    }

    public function testToolsListFiltersByAbilities(): void
    {
        $this->registry->register(new Tool(
            name: 'public_tool',
            description: 'Public tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => WorkflowResult::success(),
            abilities: [],
        ));
        $this->registry->register(new Tool(
            name: 'admin_tool',
            description: 'Admin tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => WorkflowResult::success(),
            abilities: ['admin'],
        ));

        $request = '{"jsonrpc": "2.0", "id": 2, "method": "tools/list", "params": {}}';

        $response = $this->protocol->handle($request, ['read']);

        $decoded = json_decode($response, true);

        $this->assertCount(1, $decoded['result']['tools']);
        $this->assertEquals('public_tool', $decoded['result']['tools'][0]['name']);
    }

    public function testToolsCallExecutesHandler(): void
    {
        $this->registry->register(new Tool(
            name: 'test_tool',
            description: 'Test tool',
            inputSchema: [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
            ],
            handler: fn(array $input) => WorkflowResult::success(
                completed: [['id' => $input['id'], 'action' => 'done']],
            ),
        ));

        $request = '{"jsonrpc": "2.0", "id": 3, "method": "tools/call", "params": {"name": "test_tool", "arguments": {"id": 42}}}';

        $response = $this->protocol->handle($request);

        $decoded = json_decode($response, true);

        $this->assertArrayHasKey('result', $decoded);
        $this->assertArrayHasKey('content', $decoded['result']);
    }

    public function testToolsCallUnknownToolReturnsError(): void
    {
        $request = '{"jsonrpc": "2.0", "id": 3, "method": "tools/call", "params": {"name": "unknown_tool", "arguments": {}}}';

        $response = $this->protocol->handle($request);

        $decoded = json_decode($response, true);

        $this->assertArrayHasKey('error', $decoded);
        $this->assertEquals(-32001, $decoded['error']['code']);
    }

    public function testMalformedJsonReturnsParseError(): void
    {
        $request = 'not valid json';

        $response = $this->protocol->handle($request);

        $decoded = json_decode($response, true);

        $this->assertArrayHasKey('error', $decoded);
        $this->assertEquals(-32700, $decoded['error']['code']);
    }

    public function testUnknownMethodReturnsError(): void
    {
        $request = '{"jsonrpc": "2.0", "id": 1, "method": "unknown/method", "params": {}}';

        $response = $this->protocol->handle($request);

        $decoded = json_decode($response, true);

        $this->assertArrayHasKey('error', $decoded);
        $this->assertEquals(-32601, $decoded['error']['code']);
    }

    public function testNotificationReturnsNoResponse(): void
    {
        $request = '{"jsonrpc": "2.0", "method": "initialized", "params": {}}';

        $response = $this->protocol->handle($request);

        $this->assertEmpty($response);
    }

    public function testBatchRequestReturnsError(): void
    {
        $request = '[{"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {}}]';

        $response = $this->protocol->handle($request);

        $decoded = json_decode($response, true);

        $this->assertArrayHasKey('error', $decoded);
        $this->assertEquals(-32600, $decoded['error']['code']);
    }

    public function testMissingIdOnRequestReturnsError(): void
    {
        $request = '{"jsonrpc": "2.0", "id": 1, "method": "tools/list"}';

        $response = $this->protocol->handle($request);

        $decoded = json_decode($response, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('result', $decoded);
    }

    public function testToolsCallWithInvalidParams(): void
    {
        $this->registry->register(new Tool(
            name: 'test_tool',
            description: 'Test tool',
            inputSchema: [
                'type' => 'object',
                'required' => ['id'],
                'properties' => ['id' => ['type' => 'integer']],
            ],
            handler: fn(array $input) => WorkflowResult::success(),
        ));

        $request = '{"jsonrpc": "2.0", "id": 3, "method": "tools/call", "params": {"name": "test_tool", "arguments": {}}}';

        $response = $this->protocol->handle($request);

        $decoded = json_decode($response, true);

        $this->assertArrayHasKey('error', $decoded);
        $this->assertEquals(-32002, $decoded['error']['code']);
    }

    public function testToolsCallHandlerExceptionReturnsIsErrorResult(): void
    {
        $this->registry->register(new Tool(
            name: 'failing_tool',
            description: 'Failing tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => throw new \RuntimeException('Handler exploded'),
        ));

        $request = '{"jsonrpc": "2.0", "id": 3, "method": "tools/call", "params": {"name": "failing_tool", "arguments": {}}}';

        $response = $this->protocol->handle($request);

        $decoded = json_decode($response, true);

        // MCP spec: handler errors are JSON-RPC success with isError:true, not JSON-RPC error
        $this->assertArrayHasKey('result', $decoded);
        $this->assertArrayNotHasKey('error', $decoded);
        $this->assertTrue($decoded['result']['isError']);
        $this->assertNotEmpty($decoded['result']['content']);
        $this->assertEquals('text', $decoded['result']['content'][0]['type']);
        $payload = json_decode($decoded['result']['content'][0]['text'], true);
        $this->assertStringContainsString('Handler exploded', $payload['metadata']['error']);
    }

    public function testInitializeReturnsCustomServerName(): void
    {
        $protocol = new McpProtocol($this->registry, serverName: 'MyApp', serverVersion: '2.5');
        $request = '{"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {}}';

        $decoded = json_decode($protocol->handle($request), true);

        $this->assertEquals('MyApp', $decoded['result']['serverInfo']['name']);
        $this->assertEquals('2.5', $decoded['result']['serverInfo']['version']);
    }
}
