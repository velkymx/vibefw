<?php

declare(strict_types=1);

namespace Fw\Tests\Architecture;

use Closure;
use Fw\Core\Router;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verify Router lockdown: no closures as route handlers.
 */
final class RouterLockdownTest extends TestCase
{
    #[Test]
    public function routerRejectsClosureHandler(): void
    {
        $router = new Router();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Closure route handlers are not allowed');

        $router->get('/test', function () {
            return 'hello';
        });
    }

    #[Test]
    public function routerRejectsClosureInPost(): void
    {
        $router = new Router();

        $this->expectException(InvalidArgumentException::class);

        $router->post('/test', fn() => 'hello');
    }

    #[Test]
    public function routerAcceptsArrayHandler(): void
    {
        $router = new Router();

        // Should not throw — array handlers are allowed
        $router->get('/test', [self::class, 'dummyHandler']);

        $this->assertTrue(true);
    }

    public static function dummyHandler(): void {}
}
