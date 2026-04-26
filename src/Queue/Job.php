<?php

declare(strict_types=1);

namespace Fw\Queue;

use Throwable;

abstract class Job implements JobInterface
{
    public private(set) int $attempts = 0;

    public private(set) ?string $jobId = null;

    protected string $queue = 'default';

    protected int $maxAttempts = 3;

    protected int $retryAfter = 60;

    protected int $delay = 0;

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    public function getDelay(): int
    {
        return $this->delay;
    }

    public function onQueue(string $queue): static
    {
        $this->queue = $queue;
        return $this;
    }

    public function delay(int $seconds): static
    {
        $this->delay = $seconds;
        return $this;
    }

    public function tries(int $attempts): static
    {
        $this->maxAttempts = $attempts;
        return $this;
    }

    public function incrementAttempts(): void
    {
        $this->attempts++;
    }

    public function setAttempts(int $attempts): void
    {
        $this->attempts = $attempts;
    }

    public function setJobId(string $id): void
    {
        $this->jobId = $id;
    }

    public function failed(Throwable $exception): void
    {
        // Override in child classes to handle failures
    }

    abstract public function handle(): void;
}
