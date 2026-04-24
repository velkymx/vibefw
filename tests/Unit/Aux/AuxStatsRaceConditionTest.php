<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\AuxStats;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Item #55: `AuxStats::record()` did a read-modify-write cycle
 * entirely from the in-memory copy that was snapshotted at
 * construction. Two concurrent workers each hold a stale view —
 * whoever persists last wins, losing the other's record.
 *
 * `persist()` uses `LOCK_EX` for the write, but a file lock on the
 * *write* alone can't prevent lost updates: the computation is
 * already against a stale copy. The fix moves the lock around the
 * full read-modify-write, re-reading from disk inside the lock.
 *
 * [R59] opens the file with `flock(LOCK_EX)`, reloads from disk,
 * merges the new record, writes back, and releases.
 *
 * The tests don't fork — we don't need real concurrency to expose
 * the lost-update bug. Two long-lived instances pointed at the same
 * file deterministically reproduce it: each has its own stale
 * `$this->stats`. Pre-fix, the second `record()` overwrites the
 * first. Post-fix, it reloads and merges.
 */
final class AuxStatsRaceConditionTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/fw_aux_stats_race_' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->path)) {
            unlink($this->path);
        }
    }

    #[Test]
    public function staleInstanceDoesNotOverwritePeerUpdates(): void
    {
        $a = new AuxStats($this->path);
        $b = new AuxStats($this->path); // also snapshots empty in-memory

        $a->record('tool_a', 100.0, false);
        // b still holds empty in-memory. Pre-fix, the next call
        // persists b's view of the world ({}  + tool_b only) and
        // wipes tool_a from disk.
        $b->record('tool_b', 50.0, false);

        $fresh = new AuxStats($this->path);
        $snap = $fresh->snapshot();

        $this->assertArrayHasKey('tool_a', $snap, 'tool_a recorded by instance A must survive instance B persisting');
        $this->assertArrayHasKey('tool_b', $snap);
        $this->assertSame(1, $snap['tool_a']['count']);
        $this->assertSame(1, $snap['tool_b']['count']);
    }

    #[Test]
    public function staleInstanceRecordingSameToolPreservesPeerCount(): void
    {
        $a = new AuxStats($this->path);
        $b = new AuxStats($this->path);

        $a->record('shared_tool', 100.0, false);
        $b->record('shared_tool', 200.0, false);

        $fresh = new AuxStats($this->path);
        $snap = $fresh->snapshot();

        $this->assertSame(
            2,
            $snap['shared_tool']['count'],
            'both records must accumulate; B must not overwrite A with stale count=0'
        );
    }

    #[Test]
    public function errorCountsAccumulateAcrossStaleInstances(): void
    {
        $a = new AuxStats($this->path);
        $b = new AuxStats($this->path);

        $a->record('shared', 10.0, isError: true);
        $b->record('shared', 10.0, isError: true);

        $snap = (new AuxStats($this->path))->snapshot();

        $this->assertSame(2, $snap['shared']['error_count']);
        $this->assertSame(2, $snap['shared']['count']);
    }

    #[Test]
    public function sourceLocksAndReloadsInsideRecord(): void
    {
        $rm = new ReflectionMethod(AuxStats::class, 'record');
        $start = (int) $rm->getStartLine();
        $end = (int) $rm->getEndLine();
        $lines = file((string) $rm->getFileName()) ?: [];
        $body = implode('', array_slice($lines, $start - 1, $end - $start + 1));

        $this->assertMatchesRegularExpression(
            '/flock\s*\(/',
            $body,
            'record() must hold a file lock around the read-modify-write cycle'
        );
        $this->assertMatchesRegularExpression(
            '/\$this->load\s*\(\s*\)/',
            $body,
            'record() must reload from disk inside the lock (not trust in-memory state)'
        );
    }
}
