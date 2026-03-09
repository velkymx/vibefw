<?php

declare(strict_types=1);

namespace Fw\Queue;

final class Queue
{
    private DriverInterface $driver;

    private string $defaultQueue = 'default';

    public function __construct(DriverInterface $driver)
    {
        $this->driver = $driver;
    }

    public function setDefaultQueue(string $queue): self
    {
        $this->defaultQueue = $queue;
        return $this;
    }

    public function dispatch(JobInterface $job): string
    {
        return $this->driver->push($job);
    }

    public function dispatchAfter(int $delay, JobInterface $job): string
    {
        return $this->driver->later($delay, $job);
    }

    public function pop(?string $queue = null): ?array
    {
        return $this->driver->pop($queue ?? $this->defaultQueue);
    }

    public function delete(string $jobId): bool
    {
        return $this->driver->delete($jobId);
    }

    public function release(string $jobId, int $delay = 0): bool
    {
        return $this->driver->release($jobId, $delay);
    }

    public function size(?string $queue = null): int
    {
        return $this->driver->size($queue ?? $this->defaultQueue);
    }

    public function clear(?string $queue = null): int
    {
        return $this->driver->clear($queue ?? $this->defaultQueue);
    }

    public function getDriver(): DriverInterface
    {
        return $this->driver;
    }
}
