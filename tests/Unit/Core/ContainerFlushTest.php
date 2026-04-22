<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Core\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Locks in Container::flush() behaviour for request-boundary cleanup
 * in worker mode.
 *
 * flush() accepts an optional string key: with no argument it clears
 * every non-global cached instance in the current context (main or
 * the current Fiber); with a string key it clears only that single
 * binding from the current context while leaving the rest intact.
 * Global instances (registered with $global = true) are unaffected —
 * those are the responsibility of flushGlobal()/flushAll().
 */
final class ContainerFlushTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
    }

    #[Test]
    public function flushRemovesAllCachedFiberLocalSingletons(): void
    {
        $this->container->singleton('svc.a', fn () => new stdClass());
        $this->container->singleton('svc.b', fn () => new stdClass());

        $firstA = $this->container->get('svc.a');
        $firstB = $this->container->get('svc.b');

        $this->container->flush();

        $this->assertNotSame($firstA, $this->container->get('svc.a'));
        $this->assertNotSame($firstB, $this->container->get('svc.b'));
    }

    #[Test]
    public function flushWithKeyOnlyClearsThatBinding(): void
    {
        $this->container->singleton('svc.keep', fn () => new stdClass());
        $this->container->singleton('svc.drop', fn () => new stdClass());

        $keep = $this->container->get('svc.keep');
        $drop = $this->container->get('svc.drop');

        $this->container->flush('svc.drop');

        $this->assertSame($keep, $this->container->get('svc.keep'), 'flush(key) must not touch other bindings');
        $this->assertNotSame($drop, $this->container->get('svc.drop'), 'flush(key) must invalidate the named binding');
    }

    #[Test]
    public function flushDoesNotRemoveGlobalInstances(): void
    {
        $this->container->singleton('svc.global', fn () => new stdClass(), true);

        $first = $this->container->get('svc.global');

        $this->container->flush();

        $this->assertSame(
            $first,
            $this->container->get('svc.global'),
            'flush() must leave global instances alone — flushGlobal() is the global equivalent.',
        );
    }
}
