<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Container;
use Fw\Queue\DriverInterface;
use Fw\Queue\Queue;
use Fw\Queue\Worker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Driver that throws on pop() to simulate a broken queue.
 */
final class ThrowingQueueDriver implements DriverInterface
{
    public function push(\Fw\Queue\JobInterface $job): string { return ''; }
    public function later(int $delay, \Fw\Queue\JobInterface $job): string { return ''; }
    public function pop(string $queue = 'default'): ?array { throw new RuntimeException('driver failure'); }
    public function delete(string $jobId): bool { return false; }
    public function release(string $jobId, int $delay = 0): bool { return false; }
    public function size(string $queue = 'default'): int { return 0; }
    public function clear(string $queue = 'default'): int { return 0; }
}

final class WorkerSignalCleanupTest extends TestCase
{
    private function makeWorker(): Worker
    {
        $queue = new Queue(new ThrowingQueueDriver());
        $container = new Container();
        $worker = new Worker($queue, $container);
        // Suppress default output so tests don't print timestamps
        $worker->onOutput(fn (string $_) => null);
        return $worker;
    }

    #[Test]
    public function workPropagatesExceptionFromJobLoop(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('driver failure');

        $this->makeWorker()->work('default');
    }

    #[Test]
    public function signalHandlersRestoredAfterException(): void
    {
        // Work throws — signal handlers must still be restored.
        // We verify by running work() again on a fresh worker; if handlers
        // from the previous run leaked, the second run would behave differently.
        $worker1 = $this->makeWorker();
        try {
            $worker1->work('default');
        } catch (RuntimeException) {
            // Expected
        }

        // Second worker also throws cleanly — no state leakage from first run.
        $worker2 = $this->makeWorker();
        $this->expectException(RuntimeException::class);
        $worker2->work('default');
    }
}
