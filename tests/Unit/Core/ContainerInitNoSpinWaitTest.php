<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Core\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Item H11 — Container::getInstance() must not spin-wait on concurrent
 * initialization. The old code looped up to 1000 iterations of
 * usleep(100) + Fiber::suspend(), which pins CPU under contention and
 * silently drops through after the budget is exhausted.
 *
 * The fixed code throws immediately on re-entrant calls (deadlock
 * detection) instead of busy-looping.
 */
final class ContainerInitNoSpinWaitTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::reset();
    }

    #[Test]
    public function getInstanceDoesNotContainSpinWaitLoop(): void
    {
        $body = $this->methodBody('getInstance');

        $this->assertStringNotContainsString(
            'usleep(100)',
            $body,
            'Container::getInstance() must not use usleep() spin-wait — it pins CPU under contention.',
        );
        $this->assertStringNotContainsString(
            '$spins < 1000',
            $body,
            'Container::getInstance() must not have a spin budget — throw immediately on re-entrant call instead.',
        );
    }

    #[Test]
    public function getInstanceThrowsOnReentrantCall(): void
    {
        $body = $this->methodBody('getInstance');

        $this->assertStringContainsString(
            'RuntimeException',
            $body,
            'Container::getInstance() must throw RuntimeException on re-entrant call (deadlock detection) instead of spinning.',
        );
    }

    #[Test]
    public function getInstanceReturnsImmediatelyWhenAlreadyInitialized(): void
    {
        Container::setInstance(new Container());
        $first = Container::getInstance();
        $second = Container::getInstance();

        $this->assertSame($first, $second, 'Container::getInstance() must return the same instance on subsequent calls.');
    }

    private function methodBody(string $method): string
    {
        $ref = new ReflectionMethod(Container::class, $method);
        $file = file($ref->getFileName());
        $start = $ref->getStartLine() - 1;
        $end = $ref->getEndLine();
        return implode('', array_slice($file, $start, $end - $start));
    }
}
