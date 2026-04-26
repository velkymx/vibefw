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
     * Set the attempt counter to a specific value.
     * Used by drivers to sync in-memory state after deserialization.
     */
    public function setAttempts(int $attempts): void;

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void;
}
