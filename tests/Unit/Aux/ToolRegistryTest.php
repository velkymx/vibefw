<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\Tool;
use Fw\Aux\ToolRegistry;
use Fw\Aux\WorkflowResult;
use Fw\Support\Option;
use Fw\Support\Result;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase
{
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry();
    }

    public function testRegisterAndGetTool(): void
    {
        $tool = new Tool(
            name: 'test_tool',
            description: 'A test tool',
            inputSchema: ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            handler: fn(array $input) => WorkflowResult::success(),
        );

        $result = $this->registry->register($tool);

        $this->assertSame($this->registry, $result);

        $found = $this->registry->get('test_tool');
        $this->assertTrue($found->isSome());
        $this->assertSame($tool, $found->get());
    }

    public function testGetNonExistentToolReturnsNone(): void
    {
        $found = $this->registry->get('non_existent');

        $this->assertTrue($found->isNone());
    }

    public function testAllReturnsAllRegisteredTools(): void
    {
        $tool1 = new Tool(
            name: 'tool_one',
            description: 'Tool 1',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => WorkflowResult::success(),
        );
        $tool2 = new Tool(
            name: 'tool_two',
            description: 'Tool 2',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => WorkflowResult::success(),
        );

        $this->registry->register($tool1);
        $this->registry->register($tool2);

        $all = $this->registry->all();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('tool_one', $all);
        $this->assertArrayHasKey('tool_two', $all);
    }

    public function testAllForFiltersByAbilities(): void
    {
        $publicTool = new Tool(
            name: 'public_tool',
            description: 'Public tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => WorkflowResult::success(),
            abilities: [],
        );
        $adminTool = new Tool(
            name: 'admin_tool',
            description: 'Admin tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => WorkflowResult::success(),
            abilities: ['admin'],
        );

        $this->registry->register($publicTool);
        $this->registry->register($adminTool);

        $filtered = $this->registry->allFor(['read']);

        $this->assertCount(1, $filtered);
        $this->assertArrayHasKey('public_tool', $filtered);
        $this->assertArrayNotHasKey('admin_tool', $filtered);
    }

    public function testAllForWithAdminAbilityIncludesAdminTool(): void
    {
        $publicTool = new Tool(
            name: 'public_tool',
            description: 'Public tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => WorkflowResult::success(),
            abilities: [],
        );
        $adminTool = new Tool(
            name: 'admin_tool',
            description: 'Admin tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => WorkflowResult::success(),
            abilities: ['admin'],
        );

        $this->registry->register($publicTool);
        $this->registry->register($adminTool);

        $filtered = $this->registry->allFor(['admin']);

        $this->assertCount(2, $filtered);
    }

    public function testCallExecutesHandler(): void
    {
        $handlerCalled = false;
        $tool = new Tool(
            name: 'test_tool',
            description: 'Test tool',
            inputSchema: ['type' => 'object'],
            handler: function (array $input) use (&$handlerCalled) {
                $handlerCalled = true;
                return WorkflowResult::success(['completed' => $input]);
            },
        );

        $this->registry->register($tool);

        $result = $this->registry->call('test_tool', ['foo' => 'bar']);

        $this->assertTrue($handlerCalled);
        $this->assertTrue($result->isOk());
    }

    public function testCallNonExistentToolReturnsError(): void
    {
        $result = $this->registry->call('non_existent', []);

        $this->assertTrue($result->isErr());
    }

    public function testCallWithMissingRequiredParamFails(): void
    {
        $tool = new Tool(
            name: 'test_tool',
            description: 'Test tool',
            inputSchema: [
                'type' => 'object',
                'required' => ['id'],
                'properties' => ['id' => ['type' => 'integer']],
            ],
            handler: fn(array $input) => WorkflowResult::success(),
        );

        $this->registry->register($tool);

        $result = $this->registry->call('test_tool', []);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('id', $result->unwrapErr()->getMessage());
    }

    public function testCallWithWrongTypeFails(): void
    {
        $tool = new Tool(
            name: 'test_tool',
            description: 'Test tool',
            inputSchema: [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
            ],
            handler: fn(array $input) => WorkflowResult::success(),
        );

        $this->registry->register($tool);

        $result = $this->registry->call('test_tool', ['id' => 'not-an-integer']);

        $this->assertTrue($result->isErr());
    }

    public function testCallHandlerErrorIsWrappedInResult(): void
    {
        $tool = new Tool(
            name: 'test_tool',
            description: 'Test tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => throw new \RuntimeException('Handler failed'),
        );

        $this->registry->register($tool);

        $result = $this->registry->call('test_tool', []);

        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(\RuntimeException::class, $result->unwrapErr());
    }

    public function testCallWithAbilitiesRestrictsAccess(): void
    {
        $adminTool = new Tool(
            name: 'admin_tool',
            description: 'Admin tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => WorkflowResult::success(),
            abilities: ['admin'],
        );

        $this->registry->register($adminTool);

        $result = $this->registry->call('admin_tool', [], []);

        $this->assertTrue($result->isErr());
    }

    public function testCallWithMatchingAbilitySucceeds(): void
    {
        $adminTool = new Tool(
            name: 'admin_tool',
            description: 'Admin tool',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => WorkflowResult::success(),
            abilities: ['admin'],
        );

        $this->registry->register($adminTool);

        $result = $this->registry->call('admin_tool', [], ['admin']);

        $this->assertTrue($result->isOk());
    }
}
