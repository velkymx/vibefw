<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\Mcp\McpError;
use Fw\Aux\Mcp\McpMessage;
use PHPUnit\Framework\TestCase;

final class McpMessageTest extends TestCase
{
    public function testRequestCreation(): void
    {
        $msg = McpMessage::request(1, 'tools/list', ['abc' => 'def']);

        $this->assertTrue($msg->isRequest());
        $this->assertFalse($msg->isNotification());
        $this->assertFalse($msg->isResponse());
        $this->assertEquals('2.0', $msg->jsonrpc);
        $this->assertEquals(1, $msg->id);
        $this->assertEquals('tools/list', $msg->method);
        $this->assertEquals(['abc' => 'def'], $msg->params);
    }

    public function testNotificationCreation(): void
    {
        $msg = McpMessage::notification('initialize', ['protocolVersion' => '2024-11-05']);

        $this->assertFalse($msg->isRequest());
        $this->assertTrue($msg->isNotification());
        $this->assertFalse($msg->isResponse());
        $this->assertNull($msg->id);
    }

    public function testResponseCreation(): void
    {
        $msg = McpMessage::response(1, ['tools' => []]);

        $this->assertFalse($msg->isRequest());
        $this->assertFalse($msg->isNotification());
        $this->assertTrue($msg->isResponse());
        $this->assertEquals(['tools' => []], $msg->result);
    }

    public function testErrorResponseCreation(): void
    {
        $error = McpError::toolNotFound('test_tool');
        $msg = McpMessage::errorResponse(1, $error);

        $this->assertTrue($msg->isResponse());
        $this->assertNotNull($msg->error);
        $this->assertEquals(-32001, $msg->error->code);
    }

    public function testFromArrayWithRequest(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'test', 'arguments' => []],
        ];

        $msg = McpMessage::fromArray($data);

        $this->assertTrue($msg->isRequest());
        $this->assertEquals('2.0', $msg->jsonrpc);
        $this->assertEquals(1, $msg->id);
        $this->assertEquals('tools/call', $msg->method);
        $this->assertEquals(['name' => 'test', 'arguments' => []], $msg->params);
    }

    public function testFromArrayWithNotification(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'method' => 'initialized',
        ];

        $msg = McpMessage::fromArray($data);

        $this->assertTrue($msg->isNotification());
        $this->assertNull($msg->id);
    }

    public function testFromArrayWithError(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => [
                'code' => -32601,
                'message' => 'Method not found',
                'data' => ['method' => 'unknown'],
            ],
        ];

        $msg = McpMessage::fromArray($data);

        $this->assertTrue($msg->isResponse());
        $this->assertNotNull($msg->error);
        $this->assertEquals(-32601, $msg->error->code);
        $this->assertEquals(['method' => 'unknown'], $msg->error->data);
    }

    public function testToArrayRoundTrip(): void
    {
        $original = McpMessage::request(42, 'tools/call', ['name' => 'foo', 'arguments' => ['id' => 1]]);

        $recreated = McpMessage::fromArray($original->toArray());

        $this->assertEquals($original->jsonrpc, $recreated->jsonrpc);
        $this->assertEquals($original->id, $recreated->id);
        $this->assertEquals($original->method, $recreated->method);
        $this->assertEquals($original->params, $recreated->params);
    }

    public function testResponseToArrayRoundTrip(): void
    {
        $original = McpMessage::response(1, ['data' => 'test']);

        $recreated = McpMessage::fromArray($original->toArray());

        $this->assertEquals($original->result, $recreated->result);
    }

    public function testErrorResponseToArrayRoundTrip(): void
    {
        $error = McpError::invalidParams('Missing field');
        $original = McpMessage::errorResponse(5, $error);

        $recreated = McpMessage::fromArray($original->toArray());

        $this->assertNotNull($recreated->error);
        $this->assertEquals($error->code, $recreated->error->code);
    }
}
