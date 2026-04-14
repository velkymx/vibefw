<?php

declare(strict_types=1);

namespace Fw\Async;

use Fiber;
use LogicException;
use SplQueue;
use Throwable;

/**
 * Central event loop managing Fibers and I/O.
 *
 * Provides the core mechanism for suspending and resuming Fibers
 * during async operations like database queries and HTTP requests.
 */
final class EventLoop
{
    private static ?self $instance = null;

    /** @var SplQueue<callable> */
    private SplQueue $deferred;

    /** @var array<int, array{stream: resource, callback: callable}> Keyed by monotonic watcher ID */
    private array $readStreams = [];

    /** @var array<int, int> Maps (int)$stream → watcher ID for reverse lookup after stream_select */
    private array $readStreamIds = [];

    /** @var array<int, array{stream: resource, callback: callable}> Keyed by monotonic watcher ID */
    private array $writeStreams = [];

    /** @var array<int, int> Maps (int)$stream → watcher ID for reverse lookup after stream_select */
    private array $writeStreamIds = [];

    /** @var array<int, array{fiber: Fiber, timeout: float}> */
    private array $timers = [];

    private int $timerIdCounter = 0;

    private int $streamIdCounter = 0;

    private bool $running = false;

    public function __construct()
    {
        $this->deferred = new SplQueue();
    }

    /**
     * Get the singleton event loop instance.
     *
     * @deprecated Use dependency injection via Container instead.
     *             Register EventLoop in your service provider:
     *             $container->singleton(EventLoop::class, fn() => new EventLoop());
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Set the singleton instance (for container integration).
     */
    public static function setInstance(self $loop): void
    {
        self::$instance = $loop;
    }

    /**
     * Reset the singleton instance (useful for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Suspend current Fiber and return to event loop.
     *
     * @throws LogicException If called outside of a Fiber
     */
    public static function suspend(mixed $value = null): mixed
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            throw new LogicException('Cannot suspend outside of a Fiber');
        }

        return Fiber::suspend($value);
    }

    /**
     * Schedule callback for next tick.
     */
    public function defer(callable $callback): void
    {
        $this->deferred->enqueue($callback);
    }

    /**
     * Watch stream for readability.
     *
     * @param resource $stream
     * @return int Watcher ID — pass to removeReadStream() to cancel
     */
    public function addReadStream($stream, callable $callback): int
    {
        $id = ++$this->streamIdCounter;
        $this->readStreams[$id] = ['stream' => $stream, 'callback' => $callback];
        $this->readStreamIds[(int) $stream] = $id;
        return $id;
    }

    /**
     * Watch stream for writability.
     *
     * @param resource $stream
     * @return int Watcher ID — pass to removeWriteStream() to cancel
     */
    public function addWriteStream($stream, callable $callback): int
    {
        $id = ++$this->streamIdCounter;
        $this->writeStreams[$id] = ['stream' => $stream, 'callback' => $callback];
        $this->writeStreamIds[(int) $stream] = $id;
        return $id;
    }

    /**
     * Add a timer that fires after a delay.
     *
     * @param float $delay Delay in seconds
     * @return int Timer ID
     */
    public function addTimer(float $delay, callable $callback): int
    {
        $id = ++$this->timerIdCounter;
        $timeout = microtime(true) + $delay;

        $this->timers[$id] = [
            'timeout' => $timeout,
            'callback' => $callback,
        ];

        return $id;
    }

    /**
     * Cancel a timer.
     */
    public function cancelTimer(int $timerId): void
    {
        unset($this->timers[$timerId]);
    }

    /**
     * Run the event loop until all work is done.
     */
    public function run(): void
    {
        $this->running = true;

        while ($this->running && $this->hasPendingWork()) {
            $this->tick();
        }

        $this->running = false;
    }

    /**
     * Run the event loop for a single request (stops when no pending work).
     */
    public function runOnce(): void
    {
        $this->running = true;

        while ($this->running && $this->hasPendingWork()) {
            $this->tick();

            // If only timers remain and they're all in the future, break
            if ($this->deferred->isEmpty() && empty($this->readStreams) && empty($this->writeStreams)) {
                $now = microtime(true);
                $hasReadyTimer = false;

                foreach ($this->timers as $timer) {
                    if ($timer['timeout'] <= $now) {
                        $hasReadyTimer = true;
                        break;
                    }
                }

                if (!$hasReadyTimer && !empty($this->timers)) {
                    // Sleep until next timer
                    $nextTimeout = min(array_column($this->timers, 'timeout'));
                    $sleepTime = max(0, $nextTimeout - $now);
                    if ($sleepTime > 0) {
                        usleep((int) ($sleepTime * 1000000));
                    }
                }
            }
        }

        $this->running = false;
    }

    /**
     * Stop the event loop.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Check if the event loop is running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Execute one iteration of the event loop.
     */
    public function tick(): void
    {
        // Process deferred callbacks
        $count = $this->deferred->count();
        for ($i = 0; $i < $count; $i++) {
            if ($this->deferred->isEmpty()) {
                break;
            }
            $callback = $this->deferred->dequeue();
            $callback();
        }

        // Process timers
        $this->processTimers();

        // Check streams with select()
        if (!empty($this->readStreams) || !empty($this->writeStreams)) {
            $this->processStreams();
        }
    }

    /**
     * Remove a read stream watcher.
     *
     * @param int $watcherId ID returned by addReadStream()
     * @param bool $close Whether to close the underlying stream
     */
    public function removeReadStream(int $watcherId, bool $close = false): void
    {
        $data = $this->readStreams[$watcherId] ?? null;
        unset($this->readStreams[$watcherId]);

        if ($data !== null) {
            unset($this->readStreamIds[(int) $data['stream']]);
            if ($close && is_resource($data['stream'])) {
                @fclose($data['stream']);
            }
        }
    }

    /**
     * Remove a write stream watcher.
     *
     * @param int $watcherId ID returned by addWriteStream()
     * @param bool $close Whether to close the underlying stream
     */
    public function removeWriteStream(int $watcherId, bool $close = false): void
    {
        $data = $this->writeStreams[$watcherId] ?? null;
        unset($this->writeStreams[$watcherId]);

        if ($data !== null) {
            unset($this->writeStreamIds[(int) $data['stream']]);
            if ($close && is_resource($data['stream'])) {
                @fclose($data['stream']);
            }
        }
    }

    /**
     * Close all streams and reset the event loop.
     *
     * Call between requests in worker mode to prevent resource leaks.
     */
    public function closeAllStreams(): void
    {
        foreach ($this->readStreams as $data) {
            if (is_resource($data['stream'])) {
                @fclose($data['stream']);
            }
        }
        $this->readStreams = [];
        $this->readStreamIds = [];

        foreach ($this->writeStreams as $data) {
            if (is_resource($data['stream'])) {
                @fclose($data['stream']);
            }
        }
        $this->writeStreams = [];
        $this->writeStreamIds = [];

        $this->timers = [];
        $this->deferred = new SplQueue();
    }

    /**
     * Get the count of pending deferred callbacks.
     */
    public function getDeferredCount(): int
    {
        return $this->deferred->count();
    }

    /**
     * Get the count of active read stream watchers.
     */
    public function getReadStreamCount(): int
    {
        return count($this->readStreams);
    }

    /**
     * Get the count of active write stream watchers.
     */
    public function getWriteStreamCount(): int
    {
        return count($this->writeStreams);
    }

    /**
     * Get the count of active timers.
     */
    public function getTimerCount(): int
    {
        return count($this->timers);
    }

    /**
     * Process pending timers.
     */
    private function processTimers(): void
    {
        $now = microtime(true);
        $expired = [];

        foreach ($this->timers as $id => $timer) {
            if ($timer['timeout'] <= $now) {
                $expired[$id] = $timer['callback'];
            }
        }

        foreach ($expired as $id => $callback) {
            unset($this->timers[$id]);
            $callback();
        }
    }

    /**
     * Process stream I/O using select().
     */
    private function processStreams(): void
    {
        $read = array_column($this->readStreams, 'stream');
        $write = array_column($this->writeStreams, 'stream');
        $except = null;

        if (empty($read) && empty($write)) {
            return;
        }

        // Filter out any invalid/closed streams before select
        $read = array_filter($read, fn ($s) => is_resource($s) && get_resource_type($s) !== 'Unknown');
        $write = array_filter($write, fn ($s) => is_resource($s) && get_resource_type($s) !== 'Unknown');

        if (empty($read) && empty($write)) {
            return;
        }

        // Clear any previous errors
        error_clear_last();

        // Non-blocking select with 10ms timeout
        $changed = stream_select($read, $write, $except, 0, 10000);

        // Handle stream_select errors properly
        if ($changed === false) {
            $error = error_get_last();
            // EINTR (interrupted system call) is recoverable - just retry next tick
            if ($error !== null && !str_contains($error['message'] ?? '', 'Interrupted')) {
                // Log or handle the error - remove broken streams
                $this->cleanupBrokenStreams();
            }
            return;
        }

        if ($changed === 0) {
            return;
        }

        // Handle readable streams
        foreach ($read as $stream) {
            $watcherId = $this->readStreamIds[(int) $stream] ?? null;
            if ($watcherId !== null && isset($this->readStreams[$watcherId])) {
                try {
                    ($this->readStreams[$watcherId]['callback'])($stream);
                } catch (Throwable $e) {
                    // Remove stream on callback error to prevent infinite error loops
                    $this->removeReadStream($watcherId);
                    throw $e;
                }
            }
        }

        // Handle writable streams
        foreach ($write as $stream) {
            $watcherId = $this->writeStreamIds[(int) $stream] ?? null;
            if ($watcherId !== null && isset($this->writeStreams[$watcherId])) {
                try {
                    ($this->writeStreams[$watcherId]['callback'])($stream);
                } catch (Throwable $e) {
                    // Remove stream on callback error to prevent infinite error loops
                    $this->removeWriteStream($watcherId);
                    throw $e;
                }
            }
        }
    }

    /**
     * Remove any broken or closed streams from watchers.
     *
     * Properly closes resources to prevent leaks in long-running processes.
     */
    private function cleanupBrokenStreams(): void
    {
        foreach ($this->readStreams as $watcherId => $data) {
            $stream = $data['stream'];
            $isBroken = !is_resource($stream) || get_resource_type($stream) === 'Unknown';

            if ($isBroken) {
                if (is_resource($stream)) {
                    @fclose($stream);
                }
                unset($this->readStreams[$watcherId], $this->readStreamIds[(int) $stream]);
            }
        }

        foreach ($this->writeStreams as $watcherId => $data) {
            $stream = $data['stream'];
            $isBroken = !is_resource($stream) || get_resource_type($stream) === 'Unknown';

            if ($isBroken) {
                if (is_resource($stream)) {
                    @fclose($stream);
                }
                unset($this->writeStreams[$watcherId], $this->writeStreamIds[(int) $stream]);
            }
        }
    }

    /**
     * Check if there is pending work.
     */
    private function hasPendingWork(): bool
    {
        return !$this->deferred->isEmpty()
            || !empty($this->readStreams)
            || !empty($this->writeStreams)
            || !empty($this->timers);
    }
}
