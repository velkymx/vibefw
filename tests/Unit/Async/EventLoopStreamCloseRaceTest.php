<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Async;

use Fw\Async\EventLoop;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Item #22: EventLoop::processStreams() filters out invalid streams
 * before calling `stream_select()`, but in a multi-Fiber world a stream
 * can be closed *after* the filter and *before* stream_select runs.
 * stream_select() on a closed resource emits a PHP warning and returns
 * false, which must not surface to the user as an unhandled error —
 * the loop should clean up the broken watchers and keep ticking.
 *
 * Lock in: closing a watched stream out-of-band and then ticking the
 * loop does not throw, does not surface a warning, and leaves the
 * EventLoop idle after the broken watcher is cleaned up.
 */
final class EventLoopStreamCloseRaceTest extends TestCase
{
    #[Test]
    public function runOnceSurvivesStreamClosedBetweenFilterAndSelect(): void
    {
        $loop = new EventLoop();

        // Two pipe pairs — watch both readable ends.
        [$a, $aw] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        [$b, $bw] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $loop->addReadStream($a, static fn () => null);
        $loop->addReadStream($b, static fn () => null);

        // Simulate a racing Fiber closing $b before select runs.
        fclose($b);

        // Must not throw, must not emit a PHP warning that bubbles up as an error.
        $errored = false;
        set_error_handler(static function () use (&$errored): bool {
            $errored = true;
            return true;
        });
        try {
            $loop->tick();
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($errored, 'stream_select warning on closed resource must not escape the loop');

        fclose($a);
        fclose($aw);
        fclose($bw);
    }

    #[Test]
    public function tickCleansUpBrokenWatcherAndLoopBecomesIdle(): void
    {
        $loop = new EventLoop();

        [$a, $aw] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $loop->addReadStream($a, static fn () => null);

        // Watcher is registered → loop is NOT idle yet.
        $this->assertFalse($loop->isIdle());

        fclose($a);

        set_error_handler(static fn () => true);
        try {
            $loop->tick();
        } finally {
            restore_error_handler();
        }

        // After cleanup of the only watcher, the loop should be idle —
        // otherwise runOnce() loops forever waiting for work that can
        // never complete.
        $this->assertTrue($loop->isIdle());

        fclose($aw);
    }
}
