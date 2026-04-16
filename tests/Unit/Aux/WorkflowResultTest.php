<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\WorkflowResult;
use PHPUnit\Framework\TestCase;

final class WorkflowResultTest extends TestCase
{
    public function testSuccessCreatesCompletedResult(): void
    {
        $result = WorkflowResult::success(
            completed: [
                ['id' => 1, 'action' => 'resolved', 'resolution' => 'refund processed'],
                ['id' => 2, 'action' => 'resolved', 'resolution' => 'password reset sent'],
            ],
            metadata: ['processed_at' => '2026-04-15T09:23:00Z', 'total_time_ms' => 2340]
        );

        $this->assertFalse($result->isError);
        $this->assertCount(2, $result->completed);
        $this->assertCount(0, $result->failed);
        $this->assertCount(0, $result->pending);
        $this->assertEquals('2026-04-15T09:23:00Z', $result->metadata['processed_at']);
    }

    public function testPartialCreatesMixedResult(): void
    {
        $result = WorkflowResult::partial(
            completed: [
                ['id' => 1, 'action' => 'resolved', 'resolution' => 'done'],
            ],
            failed: [
                ['id' => 2, 'error' => 'requires approval', 'reason' => '>$500 refund'],
            ],
            metadata: ['processed_at' => '2026-04-15T09:23:00Z']
        );

        $this->assertFalse($result->isError);
        $this->assertCount(1, $result->completed);
        $this->assertCount(1, $result->failed);
        $this->assertCount(0, $result->pending);
    }

    public function testErrorCreatesErrorResult(): void
    {
        $result = WorkflowResult::error('Database connection failed', ['retry_after' => 30]);

        $this->assertTrue($result->isError);
        $this->assertCount(0, $result->completed);
        $this->assertCount(0, $result->failed);
        $this->assertCount(0, $result->pending);
        $this->assertEquals(['error' => 'Database connection failed', 'retry_after' => 30], $result->metadata);
    }

    public function testToMcpContentReturnsJsonTextContent(): void
    {
        $result = WorkflowResult::success(
            completed: [['id' => 1, 'action' => 'resolved']],
            metadata: ['processed_at' => '2026-04-15T09:23:00Z']
        );

        $content = $result->toMcpContent();

        $this->assertIsArray($content);
        $this->assertCount(1, $content);
        $this->assertEquals('tool_result', $content[0]['type']);
        $this->assertIsString($content[0]['result']);

        $decoded = json_decode($content[0]['result'], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('completed', $decoded);
    }

    public function testToArrayReturnsCompleteArray(): void
    {
        $result = WorkflowResult::partial(
            completed: [['id' => 1, 'action' => 'resolved']],
            failed: [['id' => 2, 'error' => 'failed']],
            metadata: ['total' => 2]
        );

        $arr = $result->toArray();

        $this->assertIsArray($arr);
        $this->assertArrayHasKey('completed', $arr);
        $this->assertArrayHasKey('failed', $arr);
        $this->assertArrayHasKey('pending', $arr);
        $this->assertArrayHasKey('metadata', $arr);
    }

    public function testSuccessWithEmptyCompleted(): void
    {
        $result = WorkflowResult::success(completed: [], metadata: []);

        $this->assertFalse($result->isError);
        $this->assertCount(0, $result->completed);
    }

    public function testPartialWithPendingItems(): void
    {
        $result = WorkflowResult::partial(
            completed: [['id' => 1, 'action' => 'resolved']],
            failed: [],
            pending: [['id' => 2, 'status' => 'awaiting customer response']]
        );

        $this->assertCount(1, $result->pending);
    }
}
