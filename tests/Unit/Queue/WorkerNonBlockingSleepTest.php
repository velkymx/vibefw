<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Queue;

use Fw\Core\Container;
use Fw\Queue\DriverInterface;
use Fw\Queue\Queue;
use Fw\Queue\Worker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * M4: Worker used blocking sleep() that stalled signal handling.
 *
 * When no job was available the worker called sleep(3) which blocked
 * the entire process, preventing SIGTERM/SIGINT from being handled
 * for up to 3 seconds. After fix, the idle sleep is broken into 250ms
 * chunks with signal dispatch checks, so stop() takes effect promptly.
 */
final class WorkerNonBlockingSleepTest extends TestCase
{
    private function makeQueue(): Queue
    {
        $driver = new class implements DriverInterface {
            public function push(\Fw\Queue\JobInterface $job): string { return 'id'; }
            public function later(int $delay, \Fw\Queue\JobInterface $job): string { return 'id'; }
            public function pop(string $queue = 'default'): ?array { return null; }
            public function delete(string $jobId): bool { return true; }
            public function release(string $jobId, int $delay = 0): bool { return true; }
            public function size(string $queue = 'default'): int { return 0; }
            public function clear(string $queue = 'default'): int { return 0; }
        };
        return new Queue($driver);
    }

    #[Test]
    public function idleSleepMethodExists(): void
    {
        $this->assertTrue(
            method_exists(Worker::class, 'idleSleep'),
            'Worker should have an idleSleep method'
        );
    }

    #[Test]
    public function workerStopTakesEffectDuringIdleSleep(): void
    {
        $container = new Container();
        $queue = $this->makeQueue();
        $worker = new Worker($queue, $container);
        $worker->sleep(1);

        $stopCalled = false;
        $worker->onOutput(function () use ($worker, &$stopCalled): void {
            if (!$stopCalled) {
                $stopCalled = true;
                $worker->stop();
            }
        });

        $start = microtime(true);
        $worker->work('default');
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.5, $elapsed, 'Worker should respond to stop() within one sleep cycle, not block for full sleep duration');
    }

    #[Test]
    public function workerSleepMethodReturnsSelf(): void
    {
        $container = new Container();
        $queue = $this->makeQueue();
        $worker = new Worker($queue, $container);
        $result = $worker->sleep(5);
        $this->assertSame($worker, $result, 'sleep() should return $this for fluent chaining');
    }

    #[Test]
    public function workerNoLongerUsesBlockingSleepDirectly(): void
    {
        $source = file_get_contents((new \ReflectionClass(Worker::class))->getFileName());
        $this->assertStringNotContainsString(
            'sleep($this->sleep)',
            $source,
            'Worker should not call blocking sleep() directly in the work loop'
        );
    }
}
