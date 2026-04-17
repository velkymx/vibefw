<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\Tool;
use Fw\Aux\ToolRegistry;
use Fw\Aux\WorkflowResult;
use PHPUnit\Framework\TestCase;

final class ToolRegistryBudgetTest extends TestCase
{
    public function testSlowToolWithinMultiplierReturnsOriginalResult(): void
    {
        $registry = new ToolRegistry();
        $registry->setBudgetMultiplier(2.0);

        $registry->register(new Tool(
            name: 'fast_tool',
            description: 'f',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => WorkflowResult::success(),
            budget: ['estimated_duration_ms' => 10_000.0],
        ));

        $result = $registry->call('fast_tool', []);
        $this->assertTrue($result->isOk());

        $wr = $result->unwrapOr(null);
        $this->assertFalse($wr->isBudgetExceeded());
        $this->assertFalse($wr->isError);
    }

    public function testToolOverBudgetMultiplierIsFlaggedExceeded(): void
    {
        $registry = new ToolRegistry();
        $registry->setBudgetMultiplier(2.0);

        $registry->register(new Tool(
            name: 'slow_tool',
            description: 's',
            inputSchema: ['type' => 'object'],
            handler: function (array $input) {
                usleep(5_000);
                return WorkflowResult::success([['id' => 1]]);
            },
            budget: ['estimated_duration_ms' => 0.1],
        ));

        $wr = $registry->call('slow_tool', [])->unwrapOr(null);

        $this->assertTrue($wr->isBudgetExceeded());
        $this->assertTrue($wr->isError);
        $this->assertSame([['id' => 1]], $wr->completed);
        $this->assertSame(0.1, $wr->metadata['estimated_duration_ms']);
        $this->assertGreaterThan(0.1, $wr->metadata['actual_duration_ms']);
    }

    public function testToolWithoutBudgetNeverFlagsExceeded(): void
    {
        $registry = new ToolRegistry();
        $registry->setBudgetMultiplier(2.0);

        $registry->register(new Tool(
            name: 'no_budget_tool',
            description: 'n',
            inputSchema: ['type' => 'object'],
            handler: function (array $input) {
                usleep(2_000);
                return WorkflowResult::success();
            },
        ));

        $wr = $registry->call('no_budget_tool', [])->unwrapOr(null);
        $this->assertFalse($wr->isBudgetExceeded());
    }
}
