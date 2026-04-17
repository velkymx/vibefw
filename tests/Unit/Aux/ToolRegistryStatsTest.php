<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\AuxStats;
use Fw\Aux\Tool;
use Fw\Aux\ToolRegistry;
use Fw\Aux\WorkflowResult;
use PHPUnit\Framework\TestCase;

final class ToolRegistryStatsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/fw_aux_stats_' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->path)) {
            unlink($this->path);
        }
    }

    public function testCallRecordsDurationInStats(): void
    {
        $stats = new AuxStats($this->path);
        $registry = new ToolRegistry();
        $registry->setStats($stats);

        $registry->register(new Tool(
            name: 'fast_tool',
            description: 'f',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => WorkflowResult::success(),
        ));

        $registry->call('fast_tool', []);

        $snap = $stats->snapshot();
        $this->assertArrayHasKey('fast_tool', $snap);
        $this->assertSame(1, $snap['fast_tool']['count']);
        $this->assertGreaterThanOrEqual(0.0, $snap['fast_tool']['avg_duration_ms']);
    }

    public function testHandlerThrowDoesNotRecordStats(): void
    {
        $stats = new AuxStats($this->path);
        $registry = new ToolRegistry();
        $registry->setStats($stats);

        $registry->register(new Tool(
            name: 'broken_tool',
            description: 'b',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => throw new \RuntimeException('boom'),
        ));

        $registry->call('broken_tool', []);

        $this->assertArrayNotHasKey('broken_tool', $stats->snapshot());
    }

    public function testWorkflowResultErrorStillRecordedButFlaggedAsError(): void
    {
        $stats = new AuxStats($this->path);
        $registry = new ToolRegistry();
        $registry->setStats($stats);

        $registry->register(new Tool(
            name: 'partial_tool',
            description: 'p',
            inputSchema: ['type' => 'object'],
            handler: fn(array $input) => WorkflowResult::error('bad state'),
        ));

        $registry->call('partial_tool', []);

        $snap = $stats->snapshot();
        $this->assertSame(1, $snap['partial_tool']['count']);
        $this->assertSame(1, $snap['partial_tool']['error_count']);
    }
}
