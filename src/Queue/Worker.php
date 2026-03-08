<?php

declare(strict_types=1);

namespace Fw\Queue;

use Fw\Core\Container;
use Fw\Core\RequestContext;
use Throwable;

final class Worker
{
    private Queue $queue;

    private Container $container;

    private bool $shouldStop = false;

    private int $sleep = 3;

    private int $maxJobs = 0;

    private int $maxTime = 0;

    private int $memory = 128;

    private int $processedJobs = 0;

    private float $startTime;

    /** @var callable|null */
    private $outputHandler = null;

    public function __construct(Queue $queue, Container $container)
    {
        $this->queue = $queue;
        $this->container = $container;
        $this->startTime = microtime(true);
    }

    public function sleep(int $seconds): self
    {
        $this->sleep = $seconds;
        return $this;
    }

    public function maxJobs(int $count): self
    {
        $this->maxJobs = $count;
        return $this;
    }

    public function maxTime(int $seconds): self
    {
        $this->maxTime = $seconds;
        return $this;
    }

    public function memory(int $megabytes): self
    {
        $this->memory = $megabytes;
        return $this;
    }

    public function onOutput(callable $handler): self
    {
        $this->outputHandler = $handler;
        return $this;
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    public function work(string $queue = 'default'): void
    {
        $this->registerSignalHandlers();
        $this->output("Worker started for queue: $queue");

        while (!$this->shouldStop) {
            if ($this->shouldQuit()) {
                break;
            }

            $job = $this->queue->pop($queue);

            if ($job === null) {
                sleep($this->sleep);
                continue;
            }

            $this->processJob($job);
            $this->processedJobs++;

            // Clean up state between jobs to prevent memory leaks and state contamination
            $this->resetState();

            if ($this->maxJobs > 0 && $this->processedJobs >= $this->maxJobs) {
                $this->output("Max jobs ($this->maxJobs) reached, stopping...");
                break;
            }
        }

        $this->restoreSignalHandlers();
        $this->output("Worker stopped. Processed {$this->processedJobs} jobs.");
    }

    public function workOnce(string $queue = 'default'): bool
    {
        $job = $this->queue->pop($queue);

        if ($job === null) {
            return false;
        }

        $this->processJob($job);
        $this->resetState();
        return true;
    }

    private function resetState(): void
    {
        foreach ($this->container->getResettables() as $resettable) {
            $resettable->reset();
        }

        // Flush Fiber-local instances (even though worker doesn't use Fibers currently,
        // it ensures any state keyed to 'global' or current execution is cleared).
        $this->container->flush();

        // Ensure RequestContext is clear
        RequestContext::clear();
    }

    private function processJob(array $jobData): void
    {
        $jobId = $jobData['id'];
        /** @var JobInterface $job */
        $job = $jobData['job'];
        $attempts = $jobData['attempts'];

        $jobClass = get_class($job);
        $this->output("Processing job: $jobClass (ID: $jobId, Attempt: $attempts)");

        try {
            $start = microtime(true);

            $job->handle();

            $duration = round((microtime(true) - $start) * 1000, 2);
            $this->output("Job completed in {$duration}ms");

            $this->queue->delete($jobId);
        } catch (Throwable $e) {
            $this->handleFailedJob($job, $jobId, $attempts, $e);
        }
    }

    private function handleFailedJob(JobInterface $job, string $jobId, int $attempts, Throwable $e): void
    {
        $this->output("Job failed: " . $e->getMessage());

        if ($attempts < $job->getMaxAttempts()) {
            $delay = $job->getRetryAfter();
            $this->output("Releasing job for retry in {$delay}s (attempt $attempts of {$job->getMaxAttempts()})");
            $this->queue->release($jobId, $delay);
        } else {
            $this->output("Job exceeded max attempts ({$job->getMaxAttempts()}), marking as failed");

            try {
                $job->failed($e);
            } catch (Throwable $failException) {
                $this->output("Failed handler threw exception: " . $failException->getMessage());
            }

            $this->queue->delete($jobId);
            $this->logFailedJob($job, $e);
        }
    }

    private function logFailedJob(JobInterface $job, Throwable $e): void
    {
        $logFile = BASE_PATH . '/storage/logs/failed_jobs.log';
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        // Rotate log file if it exceeds 10MB to prevent unbounded growth
        $this->rotateLogIfNeeded($logFile, 10 * 1024 * 1024);

        $sanitizedTrace = $this->sanitizeStackTrace($e->getTrace());

        $entry = sprintf(
            "[%s] %s: %s\nStack trace:\n%s\n\n",
            date('Y-m-d H:i:s'),
            get_class($job),
            $e->getMessage(),
            $sanitizedTrace
        );

        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    private function rotateLogIfNeeded(string $logFile, int $maxSize): void
    {
        if (!file_exists($logFile)) {
            return;
        }

        $size = @filesize($logFile);
        if ($size === false || $size < $maxSize) {
            return;
        }

        // Keep one backup file
        $backup = $logFile . '.1';
        if (file_exists($backup)) {
            @unlink($backup);
        }
        @rename($logFile, $backup);
    }

    private function sanitizeStackTrace(array $trace): string
    {
        $lines = [];
        foreach ($trace as $i => $frame) {
            $file = $frame['file'] ?? '[internal function]';
            $line = $frame['line'] ?? 0;
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $function = $frame['function'] ?? '';
            $argCount = isset($frame['args']) ? count($frame['args']) : 0;
            $argsPlaceholder = $argCount > 0 ? '...' . $argCount . ' args...' : '';
            $call = $class . $type . $function . '(' . $argsPlaceholder . ')';
            $lines[] = "#{$i} {$file}({$line}): {$call}";
        }
        return implode("\n", $lines);
    }

    private function shouldQuit(): bool
    {
        if ($this->maxTime > 0) {
            $elapsed = microtime(true) - $this->startTime;
            if ($elapsed >= $this->maxTime) {
                $this->output("Max time ({$this->maxTime}s) reached, stopping...");
                return true;
            }
        }

        $memoryUsage = memory_get_usage(true) / 1024 / 1024;
        if ($memoryUsage >= $this->memory) {
            $this->output("Memory limit ({$this->memory}MB) reached, stopping...");
            return true;
        }

        return false;
    }

    private function registerSignalHandlers(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stop());
        pcntl_signal(SIGINT, fn () => $this->stop());
    }

    private function restoreSignalHandlers(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);
    }

    private function output(string $message): void
    {
        $formatted = sprintf("[%s] %s", date('Y-m-d H:i:s'), $message);
        if ($this->outputHandler !== null) {
            ($this->outputHandler)($formatted);
        } else {
            echo $formatted . PHP_EOL;
        }
    }
}
