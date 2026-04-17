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

    public function testToMcpShapeIncludesAnnotationsWhenNonEmpty(): void
    {
        $tool = new Tool(
            name: 'destructive_tool',
            description: 'Deletes things',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => throw new \RuntimeException('Not implemented'),
            annotations: ['destructiveHint' => true, 'idempotentHint' => false],
        );

        $shape = $tool->toMcpShape();

        $this->assertArrayHasKey('annotations', $shape);
        $this->assertTrue($shape['annotations']['destructiveHint']);
        $this->assertFalse($shape['annotations']['idempotentHint']);
    }

    public function testToMcpShapeOmitsAnnotationsWhenEmpty(): void
    {
        $tool = new Tool(
            name: 'simple_tool',
            description: 'No annotations',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => throw new \RuntimeException('Not implemented'),
        );

        $shape = $tool->toMcpShape();

        $this->assertArrayNotHasKey('annotations', $shape);
    }

    public function testWithAbilitiesReturnsNewToolWithUpdatedAbilities(): void
    {
        $original = new Tool(
            name: 'base_tool',
            description: 'Original',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => throw new \RuntimeException('Not implemented'),
            abilities: ['read'],
        );

        $cloned = $original->withAbilities(['write', 'admin']);

        $this->assertSame(['write', 'admin'], $cloned->abilities);
        $this->assertSame('base_tool', $cloned->name);
        $this->assertSame(['read'], $original->abilities);
    }

    public function testBudgetEmbeddedInAnnotationsUnderNamespacedKey(): void
    {
        $tool = new Tool(
            name: 'expensive_tool',
            description: 'Costs something',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => \Fw\Aux\WorkflowResult::success(),
            budget: [
                'estimated_tokens' => 1_500,
                'estimated_duration_ms' => 2_000,
                'retry_policy' => ['max_attempts' => 3, 'backoff' => 'exponential'],
            ],
        );

        $shape = $tool->toMcpShape();

        $this->assertArrayHasKey('annotations', $shape);
        $this->assertArrayHasKey('_budget', $shape['annotations']);
        $this->assertSame(1_500, $shape['annotations']['_budget']['estimated_tokens']);
        $this->assertSame(2_000, $shape['annotations']['_budget']['estimated_duration_ms']);
        $this->assertSame('exponential', $shape['annotations']['_budget']['retry_policy']['backoff']);
    }

    public function testBudgetAndExistingAnnotationsCoexist(): void
    {
        $tool = new Tool(
            name: 'mixed_tool',
            description: 'Both',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => \Fw\Aux\WorkflowResult::success(),
            annotations: ['readOnlyHint' => true],
            budget: ['estimated_tokens' => 100],
        );

        $shape = $tool->toMcpShape();

        $this->assertTrue($shape['annotations']['readOnlyHint']);
        $this->assertSame(100, $shape['annotations']['_budget']['estimated_tokens']);
    }

    public function testEmptyBudgetDoesNotPolluteAnnotations(): void
    {
        $tool = new Tool(
            name: 'cheap_tool',
            description: 'Free',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => \Fw\Aux\WorkflowResult::success(),
        );

        $shape = $tool->toMcpShape();

        $this->assertArrayNotHasKey('annotations', $shape);
    }

    public function testBudgetExposedAsProperty(): void
    {
        $tool = new Tool(
            name: 'budgeted',
            description: 'B',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => \Fw\Aux\WorkflowResult::success(),
            budget: ['estimated_duration_ms' => 500],
        );

        $this->assertSame(['estimated_duration_ms' => 500], $tool->budget);
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
