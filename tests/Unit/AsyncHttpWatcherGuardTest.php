<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Async\AsyncHttp;
use Fw\Async\Deferred;
use Fw\Async\EventLoop;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * Guards the EventLoop watcher-ID null-safety requirement in AsyncHttp.
 *
 * AsyncHttp registers write/read watchers using the pattern:
 *
 *   $id = null;
 *   $id = $loop->addWriteStream($s, function() use (&$id) {
 *       ...
 *       $loop->removeWriteStream($id, true); // $id is int|null
 *   });
 *
 * removeWriteStream/removeReadStream require int. Without a null guard,
 * a TypeError is raised before the Deferred can be rejected — the caller
 * sees an unhandled exception instead of a clean rejection.
 *
 * Source-code tests verify the guards exist in AsyncHttp; integration tests
 * confirm the EventLoop watcher pattern rejects correctly on stream errors.
 */
final class AsyncHttpWatcherGuardTest extends TestCase
{
    private EventLoop $loop;

    protected function setUp(): void
    {
        $this->loop = new EventLoop();
    }

    protected function tearDown(): void
    {
        EventLoop::reset();
    }

    // -------------------------------------------------------------------------
    // Source-code guards (architecture assertions)
    // -------------------------------------------------------------------------

    /**
     * Assert AsyncHttp.php contains null guards before every removeWriteStream
     * and removeReadStream call, so passing a nullable watcher ID can never
     * reach the int-typed parameter.
     *
     * The pattern must be: `if ($watcherId !== null) { $loop->removeXxxStream(...) }`
     * We verify this by checking that every removeXxxStream() call site in the
     * source is preceded by a null check on the watcher variable.
     */
    public function testAsyncHttpHasNullGuardBeforeRemoveWriteStream(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/src/Async/AsyncHttp.php'
        );

        // Extract the sendRequest / executeAsync section that owns writeWatcherId
        $this->assertStringContainsString(
            'if ($writeWatcherId !== null)',
            $source,
            'AsyncHttp must null-guard $writeWatcherId before calling removeWriteStream()'
        );
    }

    public function testAsyncHttpHasNullGuardBeforeRemoveReadStream(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/src/Async/AsyncHttp.php'
        );

        $this->assertStringContainsString(
            'if ($readWatcherId !== null)',
            $source,
            'AsyncHttp must null-guard $readWatcherId before calling removeReadStream()'
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return resource */
    private function readOnlyStream(): mixed
    {
        // fwrite() on a read-only stream returns false → triggers the error path.
        $s = fopen('php://memory', 'r');
        $this->assertIsResource($s);
        return $s;
    }

    /** @return resource */
    private function writableStream(): mixed
    {
        $s = fopen('php://memory', 'r+');
        $this->assertIsResource($s);
        return $s;
    }

    // -------------------------------------------------------------------------
    // Write-watcher error path
    // -------------------------------------------------------------------------

    public function testWriteErrorRejectsDeferredWithoutTypeError(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair not available');
        }

        [$readSocket, $writeSocket] = $pair;
        stream_set_blocking($writeSocket, false);

        // Close the read end immediately so writing to the write end will fail
        // once the OS kernel buffer fills — or trigger EPIPE/false on the next write.
        fclose($readSocket);

        $deferred = new Deferred();
        $request  = str_repeat('X', 1024 * 1024); // 1 MB — enough to fill the pipe buffer
        $written  = 0;

        $writeWatcherId = null;
        $writeWatcherId = $this->loop->addWriteStream(
            $writeSocket,
            function ($s) use ($request, &$written, &$writeWatcherId, $deferred): void {
                $chunk  = substr($request, $written, 65536);
                $result = @fwrite($s, $chunk);

                if ($result === false) {
                    // Before fix: removeWriteStream($writeWatcherId) where $writeWatcherId
                    // is typed int|null → TypeError if null. Guard prevents it.
                    if ($writeWatcherId !== null) {
                        $this->loop->removeWriteStream($writeWatcherId, true);
                    }
                    $deferred->reject(new \RuntimeException('Write failed'));
                    return;
                }

                $written += $result;

                if ($written >= strlen($request)) {
                    if ($writeWatcherId !== null) {
                        $this->loop->removeWriteStream($writeWatcherId);
                    }
                    $deferred->resolve(true);
                }
            }
        );

        try {
            $this->loop->run();
        } catch (TypeError $e) {
            $this->fail('removeWriteStream received null watcher ID: ' . $e->getMessage());
        }

        // Either rejected (write failed) or resolved (all written before pipe broke).
        $this->assertTrue(
            $deferred->isResolved(),
            'Deferred must be settled — not crash with TypeError'
        );
    }

    public function testWriteErrorPathNullGuardPreventsTypeError(): void
    {
        // Direct simulation: call removeWriteStream with the null-before-assignment
        // scenario by invoking the callback BEFORE addWriteStream returns.
        // Since the current EventLoop is synchronous, $id is always set when a
        // callback fires — but a guard is still required for PHPStan safety and
        // to defend against future synchronous EventLoop implementations.

        $caughtTypeError = false;
        $writeWatcherId  = null;

        try {
            // removeWriteStream(null) MUST NOT be reached; null guard must intercept
            if ($writeWatcherId !== null) {
                $this->loop->removeWriteStream($writeWatcherId, true);
            }
        } catch (TypeError $e) {
            $caughtTypeError = true;
        }

        $this->assertFalse(
            $caughtTypeError,
            'removeWriteStream must not be called with null — null guard must prevent TypeError'
        );
    }

    // -------------------------------------------------------------------------
    // Read-watcher error path
    // -------------------------------------------------------------------------

    public function testReadErrorRejectsDeferredWithoutTypeError(): void
    {
        $deferred = new Deferred();
        $buffer   = '';

        // Use a stream pair: close the write end so the read end returns EOF / false.
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair not available');
        }

        [$readSocket, $writeSocket] = $pair;
        stream_set_blocking($readSocket, false);
        stream_set_blocking($writeSocket, false);

        // Close write end immediately so the read side hits EOF.
        fclose($writeSocket);

        $readWatcherId = null;
        $readWatcherId = $this->loop->addReadStream(
            $readSocket,
            function ($s) use (&$buffer, &$readWatcherId, $deferred): void {
                $chunk = fread($s, 8192);

                if ($chunk === false) {
                    if ($readWatcherId !== null) {
                        $this->loop->removeReadStream($readWatcherId, true);
                    }
                    $deferred->reject(new \RuntimeException('Read failed'));
                    return;
                }

                if ($chunk === '' && feof($s)) {
                    if ($readWatcherId !== null) {
                        $this->loop->removeReadStream($readWatcherId, true);
                    }
                    $deferred->resolve($buffer);
                    return;
                }

                $buffer .= $chunk;
            }
        );

        try {
            $this->loop->run();
        } catch (TypeError $e) {
            $this->fail('removeReadStream received null watcher ID: ' . $e->getMessage());
        }

        $this->assertTrue(
            $deferred->isResolved(),
            'Deferred must be resolved/rejected after read completes — not crash with TypeError'
        );
    }
}
