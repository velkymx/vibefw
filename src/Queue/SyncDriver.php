<?php

declare(strict_types=1);

namespace Fw\Queue;

use Throwable;

final class SyncDriver implements DriverInterface
{
    public function push(JobInterface $job): string
    {
        $jobId = bin2hex(random_bytes(16));

        try {
            $job->handle();
        } catch (Throwable $e) {
            $job->failed($e);
            throw $e;
        }

        return $jobId;
    }

    public function later(int $delay, JobInterface $job): string
    {
        if ($delay > 0) {
            sleep($delay);
        }

        return $this->push($job);
    }

    public function pop(string $queue = 'default'): ?array
    {
        return null;
    }

    public function delete(string $jobId): bool
    {
        return true;
    }

    public function release(string $jobId, int $delay = 0): bool
    {
        return true;
    }

    public function size(string $queue = 'default'): int
    {
        return 0;
    }

    public function clear(string $queue = 'default'): int
    {
        return 0;
    }
}
