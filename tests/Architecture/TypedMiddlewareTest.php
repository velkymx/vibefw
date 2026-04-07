<?php

declare(strict_types=1);

namespace Fw\Tests\Architecture;

use Fw\Core\Router;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Step 8: Typed middleware registration.
 *
 * - Router has a with() method accepting a class-string and callable
 * - Route groups reject plain string middleware names (must be class-strings)
 */
final class TypedMiddlewareTest extends TestCase
{
    #[Test]
    public function routerHasWithMethod(): void
    {
        $this->assertTrue(
            method_exists(Router::class, 'with'),
            'Router must have a with() method for typed middleware registration'
        );

        $ref = new \ReflectionMethod(Router::class, 'with');
        $params = $ref->getParameters();

        $this->assertCount(2, $params, 'with() must accept exactly 2 parameters');
        $this->assertSame('middleware', $params[0]->getName());
        $this->assertSame('callback', $params[1]->getName());
    }

    #[Test]
    public function withMethodAcceptsClassStringAndCallable(): void
    {
        $router = new Router();
        $called = false;

        $router->with(self::class, function (Router $router) use (&$called) {
            $called = true;
            $router->get('/test', [self::class, 'dummyAction']);
        });

        $this->assertTrue($called, 'with() callback should be invoked immediately');

        $result = $router->dispatch('GET', '/test');
        $this->assertTrue($result->isOk());

        $match = $result->unwrapOr(null);
        $this->assertContains(self::class, $match->middleware);
    }

    #[Test]
    public function groupRejectsPlainStringMiddleware(): void
    {
        $router = new Router();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('class-string');

        $router->group('/admin', function (Router $router) {
            $router->get('/dashboard', [self::class, 'dummyAction']);
        }, ['auth']);
    }

    #[Test]
    public function groupAcceptsClassStringMiddleware(): void
    {
        $router = new Router();

        // Using self::class as a stand-in for a real middleware class-string
        $router->group('/admin', function (Router $router) {
            $router->get('/dashboard', [self::class, 'dummyAction']);
        }, [self::class]);

        $result = $router->dispatch('GET', '/admin/dashboard');
        $this->assertTrue($result->isOk());

        $match = $result->unwrapOr(null);
        $this->assertContains(self::class, $match->middleware);
    }

    #[Test]
    public function middlewareMethodOnRouteRejectsPlainString(): void
    {
        $router = new Router();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('class-string');

        $router->get('/admin', [self::class, 'dummyAction'])
            ->middleware('auth');
    }

    #[Test]
    public function middlewareMethodOnRouteAcceptsClassString(): void
    {
        $router = new Router();

        $router->get('/admin', [self::class, 'dummyAction'])
            ->middleware(self::class);

        $result = $router->dispatch('GET', '/admin');
        $this->assertTrue($result->isOk());

        $match = $result->unwrapOr(null);
        $this->assertContains(self::class, $match->middleware);
    }

    #[Test]
    public function middlewareMethodAcceptsClassStringWithColon(): void
    {
        // auth:api pattern should still work if it starts with a valid class
        $router = new Router();

        $router->get('/admin', [self::class, 'dummyAction'])
            ->middleware(self::class . ':admin');

        $result = $router->dispatch('GET', '/admin');
        $this->assertTrue($result->isOk());

        $match = $result->unwrapOr(null);
        $this->assertContains(self::class . ':admin', $match->middleware);
    }

    public static function dummyAction(): void {}
}
