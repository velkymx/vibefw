<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fiber;
use Fw\Core\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContainerReflectionFixtureA
{
    public function __construct(public ContainerReflectionFixtureB $b) {}
}

final class ContainerReflectionFixtureB
{
    public function __construct() {}
}

abstract class ContainerReflectionAbstractFixture
{
    public function __construct() {}
}

final class ContainerReflectionConcurrencyTest extends TestCase
{
    #[Test]
    public function resolvesDependenciesCorrectly(): void
    {
        $container = new Container();
        $a = $container->get(ContainerReflectionFixtureA::class);
        $this->assertInstanceOf(ContainerReflectionFixtureA::class, $a);
        $this->assertInstanceOf(ContainerReflectionFixtureB::class, $a->b);
    }

    #[Test]
    public function parallelFibersResolvingSameConcreteDoNotDeadlock(): void
    {
        $container = new Container();
        $container->clearReflectionCache();

        $f1 = new Fiber(fn () => $container->get(ContainerReflectionFixtureA::class));
        $f2 = new Fiber(fn () => $container->get(ContainerReflectionFixtureA::class));

        $f1->start();
        $f2->start();

        $this->assertTrue($f1->isTerminated());
        $this->assertTrue($f2->isTerminated());
        $this->assertInstanceOf(ContainerReflectionFixtureA::class, $f1->getReturn());
        $this->assertInstanceOf(ContainerReflectionFixtureA::class, $f2->getReturn());
    }

    #[Test]
    public function secondCallAfterFailedReflectionStillWorks(): void
    {
        $container = new Container();

        try {
            $container->get(ContainerReflectionAbstractFixture::class);
            $this->fail('Expected exception on non-instantiable class.');
        } catch (\Throwable) {
            // expected
        }

        // Should not be stuck in an "initializing" limbo — second call must
        // fail with the same reason, not with a spin-deadlock error.
        $this->expectException(\InvalidArgumentException::class);
        $container->get(ContainerReflectionAbstractFixture::class);
    }
}
