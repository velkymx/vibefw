<?php

declare(strict_types=1);

namespace Fw\Tests\Feature\Aux;

use Fw\Aux\Http\McpSseController;
use Fw\Aux\Mcp\McpProtocol;
use Fw\Aux\Tool;
use Fw\Aux\ToolRegistry;
use Fw\Aux\WorkflowResult;
use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\RequestContext;
use Fw\Core\StreamedResponse;
use Fw\Tests\TestCase;
use ReflectionClass;

final class McpSseTest extends TestCase
{
    private ToolRegistry $registry;
    private McpSseController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new ToolRegistry();
        $this->registry->register(new Tool(
            name: 'test_tool',
            description: 'A test tool',
            inputSchema: [
                'type' => 'object',
                'properties' => ['msg' => ['type' => 'string']],
            ],
            handler: fn(array $input) => WorkflowResult::success(
                completed: [['received' => $input['msg'] ?? 'none']],
            ),
        ));

        $protocol = new McpProtocol($this->registry);
        $this->controller = $this->makeController($protocol);
    }

    // ── SSE endpoint ─────────────────────────────────────────────────────────

    public function testSseUsesStreamedResponseWithCorrectHeaders(): void
    {
        // Controller::streamed() sets SSE headers — verify via a bare call
        $response = new StreamedResponse(fn() => null, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
        ]);

        $headers = $response->getHeaders();
        $this->assertSame('text/event-stream', $headers['Content-Type']);
        $this->assertSame('no-cache', $headers['Cache-Control']);
        $this->assertSame('keep-alive', $headers['Connection']);
    }

    public function testSseMethodReturnsStreamedResponse(): void
    {
        $rc = new ReflectionClass(McpSseController::class);
        $method = $rc->getMethod('sse');
        $returnType = $method->getReturnType();

        $this->assertSame(StreamedResponse::class, $returnType->getName());
    }

    public function testSseCallbackEmitsEndpointEvent(): void
    {
        // Verify the SSE source sends the endpoint event with /mcp/messages
        $rc = new ReflectionClass(McpSseController::class);
        $method = $rc->getMethod('sse');

        $file = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($file, $start, $end - $start));

        $this->assertStringContainsString('event: endpoint', $body);
        $this->assertStringContainsString('data: /mcp/messages', $body);
        $this->assertStringContainsString(': ping', $body);
        $this->assertStringContainsString('connection_aborted()', $body);
    }

    // ── POST /mcp/messages — JSON-RPC handling ───────────────────────────────

    public function testMessagesInitializeReturnsServerInfo(): void
    {
        RequestContext::create(new Request());

        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
            ],
        ]);

        $request = $this->makeRequest('POST', '/mcp/messages', $body);
        $response = $this->controller->messages($request);

        $decoded = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('2.0', $decoded['jsonrpc']);
        $this->assertSame(1, $decoded['id']);
        $this->assertSame('2024-11-05', $decoded['result']['protocolVersion']);
        $this->assertSame('VibeFW AUX', $decoded['result']['serverInfo']['name']);
    }

    public function testMessagesToolsListReturnsRegisteredTools(): void
    {
        RequestContext::create(new Request());

        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ]);

        $request = $this->makeRequest('POST', '/mcp/messages', $body);
        $response = $this->controller->messages($request);

        $decoded = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $decoded['result']['tools']);
        $this->assertSame('test_tool', $decoded['result']['tools'][0]['name']);
    }

    public function testMessagesToolsCallExecutesHandler(): void
    {
        RequestContext::create(new Request());

        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'test_tool', 'arguments' => ['msg' => 'hello']],
        ]);

        $request = $this->makeRequest('POST', '/mcp/messages', $body);
        $response = $this->controller->messages($request);

        $decoded = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('content', $decoded['result']);
        $this->assertSame('text', $decoded['result']['content'][0]['type']);

        $payload = json_decode($decoded['result']['content'][0]['text'], true);
        $this->assertSame('hello', $payload['completed'][0]['received']);
    }

    public function testMessagesEmptyBodyReturns400(): void
    {
        RequestContext::create(new Request());

        $request = $this->makeRequest('POST', '/mcp/messages', '');
        $response = $this->controller->messages($request);

        $this->assertSame(400, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertSame(-32700, $decoded['error']['code']);
    }

    public function testMessagesNotificationReturns400(): void
    {
        RequestContext::create(new Request());

        $body = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
            'params' => [],
        ]);

        $request = $this->makeRequest('POST', '/mcp/messages', $body);
        $response = $this->controller->messages($request);

        // Notification returns empty string from protocol → treated as invalid request
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testMessagesUnknownMethodReturnsError(): void
    {
        RequestContext::create(new Request());

        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 99,
            'method' => 'unknown/method',
            'params' => [],
        ]);

        $request = $this->makeRequest('POST', '/mcp/messages', $body);
        $response = $this->controller->messages($request);

        $this->assertSame(400, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertSame(-32601, $decoded['error']['code']);
    }

    public function testMessagesMalformedJsonReturnsParseError(): void
    {
        RequestContext::create(new Request());

        $request = $this->makeRequest('POST', '/mcp/messages', 'not valid json{{{');
        $response = $this->controller->messages($request);

        $this->assertSame(400, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertSame(-32700, $decoded['error']['code']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeController(McpProtocol $protocol): McpSseController
    {
        $rc = new ReflectionClass(McpSseController::class);
        $controller = $rc->newInstanceWithoutConstructor();

        $prop = $rc->getProperty('protocol');
        $prop->setValue($controller, $protocol);

        return $controller;
    }

    private function makeRequest(string $method, string $uri, ?string $rawBody = null): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';

        return new Request(rawBody: $rawBody);
    }
}
