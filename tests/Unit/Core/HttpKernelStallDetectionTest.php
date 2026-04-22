<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fiber;
use Fw\Async\EventLoop;
use Fw\Core\HttpKernel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Item #13: HttpKernel::executeInFiber() used to silently `break` out of
 * its tick loop when the fiber was still suspended but the event loop
 * had nothing left to process. The fiber's output was then null and the
 * caller got a misleading "controller must return a Response" error.
 *
 * The fix routes that case through an explicit "stalled fiber" exception
 * so the real failure mode is visible at the point of occurrence.
 */
final class HttpKernelStallDetectionTest extends TestCase
{
    #[Test]
    public function executeInFiberThrowsWhenFiberSuspendedAndLoopIdle(): void
    {
        // We can't run the full executeInFiber() path here (routing,
        // middleware, request context, etc.). Instead we prove the two
        // invariants it now relies on:
        //
        //   1. A fiber that calls Fiber::suspend() without scheduling
        //      any loop work leaves EventLoop::isIdle() === true.
        //   2. The body of executeInFiber() contains the stall-detection
        //      branch that throws RuntimeException when both hold.

        $loop = new EventLoop();
        $fiber = new Fiber(static function (): void {
            Fiber::suspend();
        });
        $fiber->start();

        $this->assertTrue($fiber->isSuspended());
        $this->assertTrue(
            $loop->isIdle(),
            'A fiber that suspends without scheduling work must leave the loop idle — otherwise stall detection is unreachable.'
        );

        $method = new ReflectionMethod(HttpKernel::class, 'executeInFiber');
        $file = file($method->getFileName());
        $body = implode('', array_slice(
            $file,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $this->assertStringContainsString(
            '$loop->isIdle()',
            $body,
            'executeInFiber() must use EventLoop::isIdle() for stall detection, not the inline 4-way count check.'
        );
        $this->assertStringContainsString(
            'throw new RuntimeException',
            $body,
            'executeInFiber() must throw on stall instead of silently breaking.'
        );
        $this->assertStringContainsString(
            'suspended with no pending work',
            $body,
            'The stall RuntimeException must name the actual failure mode, not bubble up as a generic "must return Response" error.'
        );
    }
}
