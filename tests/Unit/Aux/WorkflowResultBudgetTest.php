<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\WorkflowResult;
use PHPUnit\Framework\TestCase;

final class WorkflowResultBudgetTest extends TestCase
{
    public function testBudgetExceededFactoryFlagsError(): void
    {
        $result = WorkflowResult::budgetExceeded(
            reason: 'ran 300ms over budget',
            actualMs: 450.0,
            estimatedMs: 150.0,
        );

        $this->assertTrue($result->isError);
        $this->assertTrue($result->isBudgetExceeded());
        $this->assertSame(450.0, $result->metadata['actual_duration_ms']);
        $this->assertSame(150.0, $result->metadata['estimated_duration_ms']);
        $this->assertSame('ran 300ms over budget', $result->metadata['error']);
    }

    public function testBudgetExceededPreservesExtraMetadata(): void
    {
        $result = WorkflowResult::budgetExceeded(
            reason: 'slow',
            actualMs: 100.0,
            estimatedMs: 10.0,
            metadata: ['trace_id' => 'abc'],
        );

        $this->assertSame('abc', $result->metadata['trace_id']);
        $this->assertTrue($result->metadata['budget_exceeded']);
    }

    public function testBudgetExceededPreservesCompletedWork(): void
    {
        $partial = WorkflowResult::partial(
            completed: [['id' => 1]],
            failed: [['id' => 2]],
        );

        $exceeded = $partial->markBudgetExceeded(
            reason: 'over',
            actualMs: 900.0,
            estimatedMs: 100.0,
        );

        $this->assertSame([['id' => 1]], $exceeded->completed);
        $this->assertSame([['id' => 2]], $exceeded->failed);
        $this->assertTrue($exceeded->isBudgetExceeded());
        $this->assertTrue($exceeded->isError);
    }

    public function testNonBudgetResultIsNotFlaggedExceeded(): void
    {
        $this->assertFalse(WorkflowResult::success()->isBudgetExceeded());
        $this->assertFalse(WorkflowResult::error('boom')->isBudgetExceeded());
    }
}
