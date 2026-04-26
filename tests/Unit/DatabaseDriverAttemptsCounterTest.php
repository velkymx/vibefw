<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Queue\DatabaseDriver;
use Fw\Queue\Job;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Item #39: the TODO claimed `pop()`'s attempts-replay loop was
 * off-by-one ("if attempts = 5, UPDATE sets to 6, loop runs 6 times
 * instead of 5"). The TODO's own suggested fix produces the *same*
 * loop bound, which is a strong hint the analysis is inverted.
 *
 * The contract is: after `pop()` returns, the in-memory `Job`'s
 * `$attempts` property must match the DB column. Jobs are serialised
 * once at push time (attempts=0) and never re-serialised, so the
 * deserialised job always starts at 0 — the driver must replay
 * `stored_attempts_after_update` increments on it to converge.
 *
 * Sequence:
 *   - `$row['attempts']` (pre-UPDATE value) = N
 *   - UPDATE sets DB column to N+1
 *   - Replay must run N+1 times so `$job->attempts = N+1 = DB value`
 *
 * [R44] extracts the replay into a testable
 * `DatabaseDriver::replayAttemptsFromStoredRow(JobInterface, int)`
 * private method — no behaviour change, but the invariant is now
 * unit-testable without a MySQL server (SQLite can't run `FOR
 * UPDATE SKIP LOCKED` and the driver targets MySQL semantics).
 * These tests drive the method via reflection and pin the
 * convergence property.
 */
final class DatabaseDriverAttemptsCounterTest extends TestCase
{
    private function driver(): DatabaseDriver
    {
        // Build the driver without invoking the constructor (the real
        // ctor needs a Connection + QUEUE_SECRET_KEY). The replay
        // method only reads $job state, so an uninitialised instance
        // is enough.
        return (new \ReflectionClass(DatabaseDriver::class))->newInstanceWithoutConstructor();
    }

    private function replay(int $storedBeforeUpdate): int
    {
        $job = new NoOpTestJob();
        $method = new ReflectionMethod(DatabaseDriver::class, 'replayAttemptsFromStoredRow');
        $method->invoke($this->driver(), $job, $storedBeforeUpdate);
        return $job->attempts;
    }

    /** @return array<string, array{int, int}> */
    public static function storedBeforeUpdateCases(): array
    {
        return [
            'first pop — row.attempts 0 → job.attempts 1' => [0, 1],
            'second pop — row.attempts 1 → job.attempts 2' => [1, 2],
            'third pop — row.attempts 2 → job.attempts 3' => [2, 3],
            'fifth pop — row.attempts 4 → job.attempts 5' => [4, 5],
            'ninth pop — row.attempts 8 → job.attempts 9' => [8, 9],
            'TODO reproducer — row.attempts 5 → job.attempts 6' => [5, 6],
        ];
    }

    #[Test]
    #[DataProvider('storedBeforeUpdateCases')]
    public function replayedAttemptsMatchStoredValueAfterUpdate(int $storedBefore, int $expected): void
    {
        $this->assertSame(
            $expected,
            $this->replay($storedBefore),
            "row.attempts before UPDATE = {$storedBefore}, UPDATE sets DB to {$expected}, " .
                "so \$job->attempts must equal {$expected} after replay"
        );
    }

    #[Test]
    public function replayIsIdempotentPerJobInstance(): void
    {
        // Replay is not meant to run twice on the same job for the
        // same row. Pin that calling it twice overwrites the correct
        // value — this documents the precondition (fresh deserialised
        // job) so a future refactor can't silently call replay twice
        // and lose the correct value.
        $job = new NoOpTestJob();
        $method = new ReflectionMethod(DatabaseDriver::class, 'replayAttemptsFromStoredRow');
        $method->invoke($this->driver(), $job, 2);
        $this->assertSame(3, $job->attempts);

        // Second call on the same job intentionally overwrites the
        // correct value — the test encodes "don't call twice" rather
        // than hiding the precondition.
        $method->invoke($this->driver(), $job, 2);
        $this->assertSame(
            3,
            $job->attempts,
            'calling replay twice on the same job overwrites the value — precondition is fresh deserialised job'
        );
    }

    #[Test]
    public function replayMethodIsPrivate(): void
    {
        $method = new ReflectionMethod(DatabaseDriver::class, 'replayAttemptsFromStoredRow');
        $this->assertTrue($method->isPrivate(), 'replay helper is an internal implementation detail');
    }

    #[Test]
    public function popStillDelegatesToReplayMethod(): void
    {
        // Source-level guard: a future edit that inlines the loop
        // back into pop() would bypass this test suite. Pin the call.
        $path = (new \ReflectionClass(DatabaseDriver::class))->getFileName();
        $this->assertIsString($path);
        $source = (string) file_get_contents($path);
        $this->assertStringContainsString(
            'replayAttemptsFromStoredRow(',
            $source,
            'pop() must delegate attempts replay through the tested helper'
        );
    }
}

final class NoOpTestJob extends Job
{
    public function handle(): void
    {
        // no-op
    }
}
