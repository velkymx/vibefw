<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Async;

use Fw\Async\EventLoop;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * M9: EventLoop runOnce() slept through timer wakeups when new work arrived.
 *
 * Pre-fix: When only timers remained, runOnce() called usleep() for the
 * full timer duration. If a new deferred callback or stream was added
 * during that sleep (e.g., from another fiber), the loop wouldn't wake up
 * until the timer expired, causing latency spikes.
 *
 * Post-fix: Long sleeps are broken into 100ms chunks with periodic checks
 * for new work. If new work arrives during the sleep window, the loop wakes
 * up within 100ms instead of waiting for the full timer duration.
 */
final class EventLoopTimerWakeupsTest extends TestCase
{
    private EventLoop $loop;

    protected function setUp(): void
    {
        $this->loop = new EventLoop();
    }

    protected function tearDown(): void
    {
        $this->loop->closeAllStreams();
        EventLoop::reset();
    }

    #[Test]
    public function runOnceDoesNotUseSingleLongUsleep(): void
    {
        $source = file_get_contents((new \ReflectionClass(EventLoop::class))->getFileName());
        $this->assertStringNotContainsString(
            'usleep((int) ($sleepTime * 1000000));',
            $source,
            'runOnce() must not use single long usleep() for timer sleep'
        );
    }

    #[Test]
    public function runOnceUsesChunkedSleepForTimers(): void
    {
        $source = file_get_contents((new \ReflectionClass(EventLoop::class))->getFileName());
        $this->assertStringContainsString(
            '$chunkSize = 0.1;',
            $source,
            'runOnce() should use 100ms chunk size for timer sleep'
        );
        $this->assertStringContainsString(
            'while ($sleepTime > 0',
            $source,
            'runOnce() should loop while sleeping to check for new work'
        );
    }

    #[Test]
    public function runOnceChecksForNewWorkDuringTimerSleep(): void
    {
        $source = file_get_contents((new \ReflectionClass(EventLoop::class))->getFileName());
        $this->assertStringContainsString(
            '$this->deferred->isEmpty() && empty($this->readStreams) && empty($this->writeStreams)',
            $source,
            'runOnce() should check for new work during timer sleep chunks'
        );
    }

    #[Test]
    public function runOnceStillWaitsForTimerWhenNoNewWorkArrives(): void
    {
        $timerExecuted = false;
        $startTime = microtime(true);

        // Add a timer that fires in 0.2 seconds
        $this->loop->addTimer(0.2, function () use (&$timerExecuted, &$startTime): void {
            $timerExecuted = true;
            $elapsed = microtime(true) - $startTime;
            // Timer should fire after ~200ms
            $this->assertGreaterThanOrEqual(0.15, $elapsed, 'Timer should wait for its timeout');
        });

        $this->loop->runOnce();

        $this->assertTrue($timerExecuted, 'Timer should execute');
    }

    #[Test]
    public function runOnceDoesNotSleepWhenWorkIsAvailable(): void
    {
        $deferredExecuted = false;
        $startTime = microtime(true);

        // Add a deferred callback
        $this->loop->defer(function () use (&$deferredExecuted, &$startTime): void {
            $deferredExecuted = true;
            $elapsed = microtime(true) - $startTime;
            // Should execute almost immediately
            $this->assertLessThan(0.1, $elapsed, 'Deferred should execute without waiting');
        });

        $this->loop->runOnce();

        $this->assertTrue($deferredExecuted, 'Deferred should execute');
    }

    #[Test]
    public function runOnceProcessesDeferredAddedByTimerBeforeExiting(): void
    {
        $timerExecuted = false;
        $deferredExecuted = false;

        // Add a timer that adds a deferred callback
        $this->loop->addTimer(0.1, function () use (&$timerExecuted, &$deferredExecuted): void {
            $timerExecuted = true;
            // Add deferred work from within the timer
            $this->loop->defer(function () use (&$deferredExecuted): void {
                $deferredExecuted = true;
            });
        });

        $this->loop->runOnce();

        $this->assertTrue($timerExecuted, 'Timer should execute');
        $this->assertTrue($deferredExecuted, 'Deferred added by timer should execute before runOnce exits');
    }

    #[Test]
    public function runOnceProcessesStreamAddedByTimerBeforeExiting(): void
    {
        $timerExecuted = false;
        $streamExecuted = false;

        // Create a pipe
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        // Add a timer that adds a stream watcher
        $this->loop->addTimer(0.1, function () use (&$timerExecuted, &$streamExecuted, $sockets): void {
            $timerExecuted = true;
            // Add stream watcher from within the timer
            $this->loop->addReadStream($sockets[0], function () use (&$streamExecuted, $sockets): void {
                $streamExecuted = true;
                fclose($sockets[0]);
                fclose($sockets[1]);
            });
            // Write to trigger the stream
            fwrite($sockets[1], 'test');
        });

        $this->loop->runOnce();

        $this->assertTrue($timerExecuted, 'Timer should execute');
        $this->assertTrue($streamExecuted, 'Stream added by timer should execute before runOnce exits');

        // Clean up
        if (is_resource($sockets[0])) {
            fclose($sockets[0]);
        }
        if (is_resource($sockets[1])) {
            fclose($sockets[1]);
        }
    }
}
