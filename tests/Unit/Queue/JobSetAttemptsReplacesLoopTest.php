<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Queue;

use Fw\Queue\Job;
use Fw\Queue\JobInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * M8: Queue drivers used O(n) loop to replay attempts after deserialization.
 *
 * Pre-fix: `FileDriver::pop()` and `DatabaseDriver::pop()` called
 * `incrementAttempts()` in a for loop N times to sync in-memory state
 * with the stored attempts value. For a job retried 100 times, this
 * meant 100 method calls for no reason.
 *
 * Post-fix: `Job::setAttempts(int $n)` sets the counter directly in O(1).
 * Drivers call `setAttempts($storedAttempts)` instead of looping.
 */
final class JobSetAttemptsReplacesLoopTest extends TestCase
{
    #[Test]
    public function setAttemptsMethodExistsOnJobInterface(): void
    {
        $this->assertTrue(
            method_exists(JobInterface::class, 'setAttempts'),
            'JobInterface must declare setAttempts() for O(1) attempts sync'
        );
    }

    #[Test]
    public function setAttemptsMethodExistsOnJob(): void
    {
        $this->assertTrue(
            method_exists(Job::class, 'setAttempts'),
            'Job must implement setAttempts()'
        );
    }

    #[Test]
    public function setAttemptsSetsCounterDirectly(): void
    {
        $job = new class extends Job {
            public function handle(): void {}
        };

        $this->assertSame(0, $job->attempts, 'fresh job starts at 0 attempts');

        $job->setAttempts(5);
        $this->assertSame(5, $job->attempts, 'setAttempts(5) must set counter to 5');

        $job->setAttempts(0);
        $this->assertSame(0, $job->attempts, 'setAttempts(0) must reset counter to 0');

        $job->setAttempts(100);
        $this->assertSame(100, $job->attempts, 'setAttempts(100) must set counter to 100');
    }

    #[Test]
    public function setAttemptsIsO1NotOn(): void
    {
        $job = new class extends Job {
            public function handle(): void {}
        };

        $start = microtime(true);
        $job->setAttempts(1000);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(
            0.001,
            $elapsed,
            'setAttempts(1000) must complete in <1ms (O(1) operation)'
        );
    }

    #[Test]
    public function incrementAttemptsStillWorks(): void
    {
        $job = new class extends Job {
            public function handle(): void {}
        };

        $job->setAttempts(5);
        $job->incrementAttempts();
        $this->assertSame(6, $job->attempts, 'incrementAttempts() must still work after setAttempts()');
    }

    #[Test]
    public function setAttemptsOverridesIncrementAttempts(): void
    {
        $job = new class extends Job {
            public function handle(): void {}
        };

        $job->incrementAttempts();
        $job->incrementAttempts();
        $this->assertSame(2, $job->attempts, 'incrementAttempts() increments by 1');

        $job->setAttempts(10);
        $this->assertSame(10, $job->attempts, 'setAttempts() must override the counter');
    }

    #[Test]
    public function fileDriverNoLongerUsesLoopToReplayAttempts(): void
    {
        $source = file_get_contents((new \ReflectionClass(\Fw\Queue\FileDriver::class))->getFileName());
        $this->assertStringNotContainsString(
            'for ($i = 0; $i < $payload[\'attempts\']; $i++)',
            $source,
            'FileDriver must not use for loop to replay attempts'
        );
        $this->assertStringContainsString(
            'setAttempts($payload[\'attempts\'])',
            $source,
            'FileDriver must use setAttempts() to sync attempts'
        );
    }

    #[Test]
    public function databaseDriverNoLongerUsesLoopToReplayAttempts(): void
    {
        $source = file_get_contents((new \ReflectionClass(\Fw\Queue\DatabaseDriver::class))->getFileName());
        $this->assertStringNotContainsString(
            'for ($i = 0; $i < $target; $i++)',
            $source,
            'DatabaseDriver must not use for loop to replay attempts'
        );
        $this->assertStringContainsString(
            'setAttempts(',
            $source,
            'DatabaseDriver must use setAttempts() to sync attempts'
        );
    }
}
