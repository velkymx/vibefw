<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\AuxStats;
use PHPUnit\Framework\TestCase;

final class AuxStatsTest extends TestCase
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

    public function testFirstRecordSeedsEntry(): void
    {
        $stats = new AuxStats($this->path);
        $stats->record('process_queue', 120.0, isError: false);

        $snap = $stats->snapshot();

        $this->assertSame(1, $snap['process_queue']['count']);
        $this->assertSame(120.0, $snap['process_queue']['avg_duration_ms']);
        $this->assertSame(0, $snap['process_queue']['error_count']);
    }

    public function testSecondRecordComputesRollingAverage(): void
    {
        $stats = new AuxStats($this->path);
        $stats->record('x', 100.0, false);
        $stats->record('x', 200.0, false);

        $snap = $stats->snapshot();

        $this->assertSame(2, $snap['x']['count']);
        $this->assertSame(150.0, $snap['x']['avg_duration_ms']);
    }

    public function testErrorsIncrementErrorCount(): void
    {
        $stats = new AuxStats($this->path);
        $stats->record('x', 50.0, isError: true);
        $stats->record('x', 50.0, isError: false);

        $snap = $stats->snapshot();

        $this->assertSame(1, $snap['x']['error_count']);
        $this->assertSame(2, $snap['x']['count']);
    }

    public function testPersistsToDisk(): void
    {
        $stats = new AuxStats($this->path);
        $stats->record('persisted_tool', 42.0, false);

        $reloaded = new AuxStats($this->path);
        $snap = $reloaded->snapshot();

        $this->assertArrayHasKey('persisted_tool', $snap);
        $this->assertSame(42.0, $snap['persisted_tool']['avg_duration_ms']);
    }

    public function testSeparateToolsTrackedIndependently(): void
    {
        $stats = new AuxStats($this->path);
        $stats->record('a', 10.0, false);
        $stats->record('b', 1000.0, true);

        $snap = $stats->snapshot();

        $this->assertSame(10.0, $snap['a']['avg_duration_ms']);
        $this->assertSame(1000.0, $snap['b']['avg_duration_ms']);
        $this->assertSame(1, $snap['b']['error_count']);
        $this->assertSame(0, $snap['a']['error_count']);
    }

    public function testCorruptFileRecoversToEmpty(): void
    {
        file_put_contents($this->path, 'not json{{{');

        $stats = new AuxStats($this->path);
        $stats->record('fresh', 10.0, false);

        $this->assertSame(1, $stats->snapshot()['fresh']['count']);
    }
}
