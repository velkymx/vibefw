<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\Mcp\McpError;
use PHPUnit\Framework\TestCase;

final class McpErrorTest extends TestCase
{
    public function testParseErrorCode(): void
    {
        $error = McpError::parseError('Invalid JSON');

        $this->assertEquals(-32700, $error->code);
        $this->assertEquals('Invalid JSON', $error->message);
    }

    public function testInvalidRequestCode(): void
    {
        $error = McpError::invalidRequest();

        $this->assertEquals(-32600, $error->code);
    }

    public function testMethodNotFoundCode(): void
    {
        $error = McpError::methodNotFound();

        $this->assertEquals(-32601, $error->code);
    }

    public function testInvalidParamsCode(): void
    {
        $error = McpError::invalidParams('Missing required param');

        $this->assertEquals(-32602, $error->code);
        $this->assertEquals('Missing required param', $error->message);
    }

    public function testInternalErrorCode(): void
    {
        $error = McpError::internalError();

        $this->assertEquals(-32603, $error->code);
    }

    public function testToolNotFoundIncludesToolName(): void
    {
        $error = McpError::toolNotFound('process_ticket_queue');

        $this->assertEquals(-32001, $error->code);
        $this->assertEquals("Tool 'process_ticket_queue' not found", $error->message);
        $this->assertEquals(['tool' => 'process_ticket_queue'], $error->data);
    }

    public function testToolValidationError(): void
    {
        $error = McpError::toolValidationError('Invalid queue_id', ['field' => 'queue_id', 'issue' => 'must be positive']);

        $this->assertEquals(-32002, $error->code);
        $this->assertEquals('Invalid queue_id', $error->message);
    }

    public function testToArrayExcludesNullData(): void
    {
        $error = McpError::parseError('test');

        $arr = $error->toArray();

        $this->assertArrayHasKey('code', $arr);
        $this->assertArrayHasKey('message', $arr);
        $this->assertArrayNotHasKey('data', $arr);
    }

    public function testToArrayIncludesDataWhenPresent(): void
    {
        $error = McpError::parseError('test', ['extra' => 'info']);

        $arr = $error->toArray();

        $this->assertArrayHasKey('data', $arr);
        $this->assertEquals(['extra' => 'info'], $arr['data']);
    }

    public function testConstantsAreCorrect(): void
    {
        $this->assertEquals(-32700, McpError::PARSE_ERROR);
        $this->assertEquals(-32600, McpError::INVALID_REQUEST);
        $this->assertEquals(-32601, McpError::METHOD_NOT_FOUND);
        $this->assertEquals(-32602, McpError::INVALID_PARAMS);
        $this->assertEquals(-32603, McpError::INTERNAL_ERROR);
        $this->assertEquals(-32000, McpError::SERVER_ERROR);
        $this->assertEquals(-32001, McpError::TOOL_NOT_FOUND);
        $this->assertEquals(-32002, McpError::TOOL_VALIDATION_ERROR);
    }
}
