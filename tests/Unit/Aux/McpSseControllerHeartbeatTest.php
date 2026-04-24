<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\Http\McpSseController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Item #56: `McpSseController::sse()` called `sleep($heartbeat)` —
 * typically 15s — inside the streamed-response generator. On a
 * worker-based SAPI (FrankenPHP, RoadRunner), the worker's fiber
 * for that request is pinned for the full sleep, so:
 *
 *   - a client that disconnects mid-sleep isn't noticed until the
 *     sleep returns (up to 15s of wasted worker slot),
 *   - with N concurrent SSE connections, N workers are parked,
 *     starving normal request traffic.
 *
 * [R60] replaces `sleep()` with a tight `usleep()` tick that
 * re-checks `connection_aborted()` on every iteration and only
 * emits the heartbeat line once a full cadence has elapsed
 * (tracked via `microtime(true)`). That way the worker reacts to
 * an aborted connection within ~100ms instead of up to
 * `$heartbeat` seconds.
 *
 * Behaviourally the loop is hard to probe without a real socket,
 * so these assertions pin the source shape — the exact primitives
 * the fix depends on.
 */
final class McpSseControllerHeartbeatTest extends TestCase
{
    private function sseBody(): string
    {
        $rm = new ReflectionMethod(McpSseController::class, 'sse');
        $start = (int) $rm->getStartLine();
        $end = (int) $rm->getEndLine();
        $lines = file((string) $rm->getFileName()) ?: [];
        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    #[Test]
    public function sseDoesNotCallBlockingSleep(): void
    {
        $body = $this->sseBody();

        // Word-boundary avoids matching "usleep(" substring.
        $this->assertDoesNotMatchRegularExpression(
            '/\bsleep\s*\(/',
            $body,
            'sse() must not use blocking sleep() — parks the worker fiber for full heartbeat interval'
        );
    }

    #[Test]
    public function sseUsesUsleepForFineGrainedWakeups(): void
    {
        $this->assertMatchesRegularExpression(
            '/\busleep\s*\(/',
            $this->sseBody(),
            'sse() must use usleep for short tick intervals'
        );
    }

    #[Test]
    public function sseChecksConnectionAbortedInsideLoop(): void
    {
        $this->assertMatchesRegularExpression(
            '/connection_aborted\s*\(\s*\)/',
            $this->sseBody(),
            'sse() must poll connection_aborted() so dropped clients free the worker promptly'
        );
    }

    #[Test]
    public function sseStillEmitsHeartbeatCommentLine(): void
    {
        $this->assertStringContainsString(
            ': ping',
            $this->sseBody(),
            'heartbeat payload (": ping") must still be written to keep proxies from idling out the stream'
        );
    }

    #[Test]
    public function sseUsesMicrotimeBasedCadence(): void
    {
        $this->assertMatchesRegularExpression(
            '/microtime\s*\(\s*true\s*\)/',
            $this->sseBody(),
            'sse() must schedule heartbeats against wall-clock (microtime) rather than blocking for N seconds'
        );
    }
}
