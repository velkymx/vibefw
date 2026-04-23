<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Events\DomainEvent;
use Fw\Events\EventDispatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final readonly class ResolutionTestEvent extends DomainEvent
{
    public function __construct(public string $payload)
    {
        parent::__construct();
    }
}

/**
 * Zero-arg listener — must continue to resolve via `new $class()`
 * when no resolver is configured (back-compat).
 */
final class ResolutionTestZeroArgListener
{
    public static array $seen = [];

    public function handle(ResolutionTestEvent $event): void
    {
        self::$seen[] = $event->payload;
    }
}

/**
 * Listener with required constructor deps — currently fails with a
 * cryptic "too few arguments" `ArgumentCountError` when no resolver
 * is configured. After [R32] it must throw a `RuntimeException`
 * that names the listener and explains the DI contract.
 */
final class ResolutionTestNeedsDepsListener
{
    public function __construct(
        public readonly \stdClass $dep,
    ) {}

    public function handle(ResolutionTestEvent $event): void {}
}

/**
 * Optional-arg listener — all params have defaults, so `new $class()`
 * is still safe without a resolver.
 */
final class ResolutionTestOptionalArgListener
{
    public static array $seen = [];

    public function __construct(public readonly string $tag = 'default') {}

    public function handle(ResolutionTestEvent $event): void
    {
        self::$seen[] = $this->tag . ':' . $event->payload;
    }
}

/**
 * Item #27: when EventDispatcher has no container resolver, it must
 * still resolve listeners that have no required constructor deps,
 * and it must fail *clearly* for listeners that do.
 */
final class EventDispatcherListenerResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        ResolutionTestZeroArgListener::$seen = [];
        ResolutionTestOptionalArgListener::$seen = [];
    }

    #[Test]
    public function zeroArgListenerResolvesWithoutResolver(): void
    {
        $d = new EventDispatcher();
        $d->listen(ResolutionTestEvent::class, ResolutionTestZeroArgListener::class);
        $d->dispatch(new ResolutionTestEvent('hello'));

        $this->assertSame(['hello'], ResolutionTestZeroArgListener::$seen);
    }

    #[Test]
    public function optionalArgListenerResolvesWithoutResolver(): void
    {
        $d = new EventDispatcher();
        $d->listen(ResolutionTestEvent::class, ResolutionTestOptionalArgListener::class);
        $d->dispatch(new ResolutionTestEvent('world'));

        $this->assertSame(['default:world'], ResolutionTestOptionalArgListener::$seen);
    }

    #[Test]
    public function listenerWithRequiredDepsThrowsClearErrorWithoutResolver(): void
    {
        $d = new EventDispatcher();
        $d->listen(ResolutionTestEvent::class, ResolutionTestNeedsDepsListener::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(ResolutionTestNeedsDepsListener::class);

        $d->dispatch(new ResolutionTestEvent('boom'));
    }

    #[Test]
    public function resolverIsUsedWhenConfigured(): void
    {
        $dep = new \stdClass();
        $resolver = function (string $class) use ($dep): object {
            $this->assertSame(ResolutionTestNeedsDepsListener::class, $class);
            return new ResolutionTestNeedsDepsListener($dep);
        };

        $d = new EventDispatcher($resolver);
        $d->listen(ResolutionTestEvent::class, ResolutionTestNeedsDepsListener::class);
        $d->dispatch(new ResolutionTestEvent('via-resolver'));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function resolverIsUsedEvenForZeroArgListeners(): void
    {
        $calls = 0;
        $resolver = function (string $class) use (&$calls): object {
            $calls++;
            return new $class();
        };

        $d = new EventDispatcher($resolver);
        $d->listen(ResolutionTestEvent::class, ResolutionTestZeroArgListener::class);
        $d->dispatch(new ResolutionTestEvent('resolved'));

        $this->assertSame(1, $calls, 'resolver must take precedence over `new`');
        $this->assertSame(['resolved'], ResolutionTestZeroArgListener::$seen);
    }
}
