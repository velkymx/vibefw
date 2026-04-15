<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Mutually-recursive classes to trigger a circular dependency.
 */
final class CircA
{
    public function __construct(public CircB $b) {}
}

final class CircB
{
    public function __construct(public CircA $a) {}
}

/**
 * P-04: Container::buildClass() must detect circular dependencies and throw
 * a RuntimeException rather than spinning into infinite recursion.
 */
final class ContainerCircularDepTest extends TestCase
{
    #[Test]
    public function circularDependencyThrowsRuntimeException(): void
    {
        $container = new Container();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/[Cc]ircular/');

        $container->get(CircA::class);
    }

    #[Test]
    public function circularDependencyViaGetThrowsRuntimeException(): void
    {
        $container = new Container();

        $this->expectException(RuntimeException::class);

        $container->make(CircB::class);
    }

    #[Test]
    public function nonCircularDependencyStillResolves(): void
    {
        $container = new Container();

        // stdClass has no dependencies — must resolve cleanly.
        $obj = $container->make(\stdClass::class);

        $this->assertInstanceOf(\stdClass::class, $obj);
    }
}
