<?php

declare(strict_types=1);

namespace Fw\Queue;

use Throwable;

interface JobInterface
{
    /**
     * Execute the job.
     */
    public function handle(): void;

    /**
     * Get the queue name for this job.
     */
    public function getQueue(): string;

    /**
     * Get the number of times the job may be attempted.
     */
    public function getMaxAttempts(): int;

    /**
     * Get the number of seconds to wait before retrying.
     */
    public function getRetryAfter(): int;

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void;
}
