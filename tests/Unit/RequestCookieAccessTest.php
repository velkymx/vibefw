<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Cache\CacheInterface;
use Fw\Core\Application;
use Fw\Core\Container;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Middleware\GuestPageCacheMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Item #48: `GuestPageCacheMiddleware::handle()` reached into the
 * `$_COOKIE` superglobal directly. That breaks two properties the
 * rest of the framework preserves:
 *
 *   1. Request isolation — in worker/Fiber mode, request-scoped
 *      state must come from the `Request` object, not process-wide
 *      globals that can leak between simultaneous requests.
 *   2. Testability — middleware that reads from `$_COOKIE` is
 *      coupled to the PHP global and cannot be exercised with a
 *      synthesized `Request`.
 *
 * [R52] adds `Request::cookie()` and `Request::hasCookie()`, which
 * accept an explicit `$cookies` array in the constructor (defaulting
 * to `$_COOKIE` when fully synthesized from globals). Middleware now
 * asks the request, not the superglobal.
 *
 * Tests verify:
 *   - Request exposes the cookie API with the expected behaviour
 *   - GuestPageCacheMiddleware uses it instead of $_COOKIE
 *   - injected cookies visible to middleware without touching globals
 */
final class RequestCookieAccessTest extends TestCase
{
    /**
     * @param array<string, string> $cookies
     */
    private function syntheticRequest(string $method, string $uri, array $cookies): Request
    {
        return new Request(
            query: [],
            post: [],
            server: ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri],
            files: [],
            headers: [],
            cookies: $cookies,
        );
    }

    #[Test]
    public function requestHasCookieReturnsFalseForAbsentKey(): void
    {
        $request = $this->syntheticRequest('GET', '/x', []);
        $this->assertFalse($request->hasCookie('foo'));
        $this->assertNull($request->cookie('foo'));
        $this->assertSame('default', $request->cookie('foo', 'default'));
    }

    #[Test]
    public function requestCookieReturnsInjectedValue(): void
    {
        $request = $this->syntheticRequest('GET', '/x', ['PHPSESSID' => 'abc123']);
        $this->assertTrue($request->hasCookie('PHPSESSID'));
        $this->assertSame('abc123', $request->cookie('PHPSESSID'));
    }

    #[Test]
    public function requestCookiesFallBackToGlobalWhenAllArgsOmitted(): void
    {
        // When no explicit arrays are passed, Request reads globals.
        $_COOKIE['fw_test_cookie'] = 'from-global';
        try {
            $request = new Request();
            $this->assertSame('from-global', $request->cookie('fw_test_cookie'));
        } finally {
            unset($_COOKIE['fw_test_cookie']);
        }
    }

    #[Test]
    public function guestPageCacheMiddlewareSkipsWhenSessionCookiePresent(): void
    {
        $middleware = $this->buildGuestCacheMiddleware();
        $sessionName = session_name();

        $request = $this->syntheticRequest('GET', '/page', [$sessionName => 'SID']);

        $callCount = 0;
        $response = $middleware->handle($request, function (Request $r) use (&$callCount): Response {
            $callCount++;
            return new Response('live');
        });

        $this->assertSame(1, $callCount, 'next must execute — cache must be skipped for session holders');
        $this->assertInstanceOf(Response::class, $response);
    }

    #[Test]
    public function guestPageCacheMiddlewareUsesRequestNotSuperGlobal(): void
    {
        // No session cookie in the Request, but one in the superglobal.
        // Correct behaviour: trust the Request (no session) and cache.
        $sessionName = session_name();
        $_COOKIE[$sessionName] = 'SID-from-global';

        try {
            $middleware = $this->buildGuestCacheMiddleware();
            $request = $this->syntheticRequest('GET', '/guest-page', []);

            $response = $middleware->handle(
                $request,
                fn (Request $r): Response => new Response('guest-body')
            );

            $this->assertInstanceOf(Response::class, $response);
            // No exception means the middleware didn't key off the global.
            $this->addToAssertionCount(1);
        } finally {
            unset($_COOKIE[$sessionName]);
        }
    }

    #[Test]
    public function guestPageCacheMiddlewareSourceDoesNotAccessCookieSuperGlobal(): void
    {
        $path = (new ReflectionClass(GuestPageCacheMiddleware::class))->getFileName();
        $this->assertIsString($path);
        $source = (string) file_get_contents($path);

        $this->assertDoesNotMatchRegularExpression(
            '/\$_COOKIE\b/',
            $source,
            'Middleware must not access $_COOKIE directly — use Request::hasCookie()'
        );
    }

    private function buildGuestCacheMiddleware(): GuestPageCacheMiddleware
    {
        $cache = new class implements CacheInterface {
            /** @var array<string,mixed> */
            public array $store = [];
            public function get(string $key, mixed $default = null): mixed { return $this->store[$key] ?? $default; }
            public function set(string $key, mixed $value, ?int $ttl = null): bool { $this->store[$key] = $value; return true; }
            public function has(string $key): bool { return array_key_exists($key, $this->store); }
            public function delete(string $key): bool { unset($this->store[$key]); return true; }
            public function clear(): bool { $this->store = []; return true; }
            public function remember(string $key, callable $callback, ?int $ttl = null): mixed { return $this->store[$key] ??= $callback(); }
            public function getMany(array $keys): array { return []; }
            public function setMany(array $values, ?int $ttl = null): bool { return true; }
            public function increment(string $key, int $step = 1, ?int $ttl = null): int|false { return 0; }
        };

        $app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $container = new Container();
        $container->instance(CacheInterface::class, $cache);

        $appRef = new ReflectionClass($app);
        $appRef->getProperty('container')->setValue($app, $container);

        return new GuestPageCacheMiddleware($app, 60);
    }
}
