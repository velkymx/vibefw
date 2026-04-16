<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\Tool;
use PHPUnit\Framework\TestCase;

final class ToolTest extends TestCase
{
    public function testToMcpShapeReturnsCorrectStructure(): void
    {
        $tool = new Tool(
            name: 'process_ticket_queue',
            description: 'Process all open tickets in a queue, resolve or escalate each.',
            inputSchema: [
                'type' => 'object',
                'properties' => ['queue_id' => ['type' => 'integer']],
                'required' => ['queue_id'],
            ],
            handler: fn(array $input) => throw new \RuntimeException('Not implemented'),
            abilities: ['tools:tickets'],
            annotations: ['readOnlyHint' => true],
        );

        $shape = $tool->toMcpShape();

        $this->assertEquals('process_ticket_queue', $shape['name']);
        $this->assertEquals('Process all open tickets in a queue, resolve or escalate each.', $shape['description']);
        $this->assertIsArray($shape['inputSchema']);
        $this->assertEquals('object', $shape['inputSchema']['type']);
    }

    public function testIsAccessibleWithEmptyAbilitiesIsPublic(): void
    {
        $tool = new Tool(
            name: 'public_tool',
            description: 'A public tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => throw new \RuntimeException('Not implemented'),
            abilities: [],
        );

        $this->assertTrue($tool->isAccessibleWith([]));
        $this->assertTrue($tool->isAccessibleWith(['tools:read']));
        $this->assertTrue($tool->isAccessibleWith(['any:ability']));
    }

    public function testIsAccessibleWithMatchingAbility(): void
    {
        $tool = new Tool(
            name: 'admin_tool',
            description: 'An admin tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => throw new \RuntimeException('Not implemented'),
            abilities: ['tools:admin'],
        );

        $this->assertFalse($tool->isAccessibleWith([]));
        $this->assertTrue($tool->isAccessibleWith(['tools:admin']));
        $this->assertTrue($tool->isAccessibleWith(['tools:admin', 'tools:read']));
    }

    public function testIsAccessibleWithNonMatchingAbilityFails(): void
    {
        $tool = new Tool(
            name: 'admin_tool',
            description: 'An admin tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => throw new \RuntimeException('Not implemented'),
            abilities: ['tools:admin'],
        );

        $this->assertFalse($tool->isAccessibleWith(['tools:read']));
        $this->assertFalse($tool->isAccessibleWith(['other:ability']));
    }

    public function testToolWithMultipleAbilitiesRequiresAny(): void
    {
        $tool = new Tool(
            name: 'multi_ability_tool',
            description: 'Tool with multiple abilities',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => throw new \RuntimeException('Not implemented'),
            abilities: ['tools:admin', 'tools:support'],
        );

        $this->assertFalse($tool->isAccessibleWith([]));
        $this->assertTrue($tool->isAccessibleWith(['tools:admin']));
        $this->assertTrue($tool->isAccessibleWith(['tools:support']));
        $this->assertFalse($tool->isAccessibleWith(['tools:read']));
    }

    public function testToolNameMustBeSnakeCase(): void
    {
        $tool = new Tool(
            name: 'my_tool_name',
            description: 'Test tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => throw new \RuntimeException('Not implemented'),
        );

        $this->assertEquals('my_tool_name', $tool->name);
    }

    public function testHandlerIsCallable(): void
    {
        $handlerCalled = false;
        $tool = new Tool(
            name: 'test_tool',
            description: 'Test tool',
            inputSchema: ['type' => 'object'],
            handler: function (array $input) use (&$handlerCalled) {
                $handlerCalled = true;
                return \Fw\Aux\WorkflowResult::success();
            },
        );

        $result = ($tool->handler)([]);

        $this->assertTrue($handlerCalled);
        $this->assertInstanceOf(\Fw\Aux\WorkflowResult::class, $result);
    }
}
