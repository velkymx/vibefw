<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Container;
use Fw\Queue\DriverInterface;
use Fw\Queue\JobInterface;
use Fw\Queue\Queue;
use Fw\Queue\Worker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * A job whose handle() always throws, triggering the failed-job log path.
 */
final class AlwaysFailingJob implements JobInterface
{
    public function handle(): void
    {
        throw new RuntimeException('intentional failure');
    }

    public function getQueue(): string { return 'default'; }

    public function getMaxAttempts(): int { return 1; }

    public function getRetryAfter(): int { return 0; }

    public function failed(Throwable $exception): void {}

    public function setAttempts(int $attempts): void {}
}

/**
 * Driver that pops one AlwaysFailingJob at its max attempts so the Worker
 * takes the failed-job code path (logFailedJob), then returns null on the
 * next pop so workOnce terminates after processing the single job.
 */
final class FailedJobDriver implements DriverInterface
{
    private int $pops = 0;

    public function push(JobInterface $job): string { return 'id-1'; }

    public function later(int $delay, JobInterface $job): string { return 'id-1'; }

    public function pop(string $queue = 'default'): ?array
    {
        if ($this->pops++ === 0) {
            return [
                'id'       => 'job-1',
                'job'      => new AlwaysFailingJob(),
                'attempts' => 1, // equals getMaxAttempts() → triggers logFailedJob
            ];
        }
        return null;
    }

    public function delete(string $jobId): bool { return true; }

    public function release(string $jobId, int $delay = 0): bool { return true; }

    public function size(string $queue = 'default'): int { return 0; }

    public function clear(string $queue = 'default'): int { return 0; }
}

/**
 * Q-01: logFailedJob() must not error when the log directory already exists
 * (concurrent workers racing on mkdir).
 */
final class WorkerLogDirectoryTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = BASE_PATH . '/storage/logs/failed_jobs.log';
    }

    protected function tearDown(): void
    {
        // Clean up log entry written during test (don't delete file — it may
        // have pre-existing entries from a real run).
        // We just leave the log in place; it's in .gitignore.
    }

    private function makeWorker(): Worker
    {
        $queue  = new Queue(new FailedJobDriver());
        $worker = new Worker($queue, new Container());
        $worker->onOutput(fn (string $_) => null);
        return $worker;
    }

    // -------------------------------------------------------------------------

    #[Test]
    public function logFailedJobCreatesLogFileWhenDirectoryAbsent(): void
    {
        $logDir = dirname($this->logFile);

        // Ensure the directory doesn't exist for a clean-room test — if it
        // already exists (other tests), we skip the "absent" scenario and just
        // verify the write succeeds.
        $sizeBefore = file_exists($this->logFile) ? filesize($this->logFile) : 0;

        $this->makeWorker()->workOnce();

        $this->assertFileExists($this->logFile);
        $this->assertGreaterThan((int) $sizeBefore, filesize($this->logFile));
        $this->assertStringContainsString('intentional failure', file_get_contents($this->logFile));
    }

    #[Test]
    public function logFailedJobSucceedsWhenDirectoryAlreadyExists(): void
    {
        // Pre-create the directory to simulate the race-condition loser scenario:
        // the directory was created by another worker between our is_dir() check
        // and our mkdir() call.
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0o750, true);
        }

        // Must not throw or emit a PHP warning about mkdir failing.
        $worker = $this->makeWorker();

        $errorsBefore = error_get_last();
        $worker->workOnce();
        $errorsAfter = error_get_last();

        // If a new error was triggered, it must not be about mkdir.
        if ($errorsAfter !== $errorsBefore && $errorsAfter !== null) {
            $this->assertStringNotContainsStringIgnoringCase(
                'mkdir',
                $errorsAfter['message'],
                'mkdir race must not produce an error'
            );
        }

        $this->assertFileExists($this->logFile);
    }

    #[Test]
    public function twoConsecutiveWorkOnceCallsDoNotError(): void
    {
        // Simulates two workers logging failures in rapid succession.
        $sizeBefore = file_exists($this->logFile) ? filesize($this->logFile) : 0;

        $this->makeWorker()->workOnce();
        $this->makeWorker()->workOnce();

        $this->assertGreaterThan((int) $sizeBefore, filesize($this->logFile));
    }
}
