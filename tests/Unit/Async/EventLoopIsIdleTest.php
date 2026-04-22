<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Async;

use Fw\Async\EventLoop;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Item #13 support: HttpKernel::executeInFiber() duplicates a 4-way
 * getDeferredCount/getReadStreamCount/getWriteStreamCount/getTimerCount
 * check inline. Locking that idle-detection into `EventLoop::isIdle()`
 * means there's one definition of "nothing left to process" for both
 * the kernel loop and any future stall-detection paths.
 */
final class EventLoopIsIdleTest extends TestCase
{
    #[Test]
    public function isIdleReturnsTrueOnFreshLoop(): void
    {
        $loop = new EventLoop();
        $this->assertTrue($loop->isIdle());
    }

    #[Test]
    public function isIdleReturnsFalseWhenDeferredScheduled(): void
    {
        $loop = new EventLoop();
        $loop->defer(static fn () => null);
        $this->assertFalse($loop->isIdle());
    }
}
