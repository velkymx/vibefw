<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Item C6 (part 1) — Router::dispatch() / getAllowedMethods() previously
 * fed every URI through `parse_url($uri, PHP_URL_PATH)`, which silently
 * stripped the host from a protocol-relative URI like `//evil.com/foo`
 * and matched the leftover `/foo` against registered routes.
 *
 * Post-fix: protocol-relative paths are rejected explicitly so the
 * router cannot be tricked into matching on a host-bearing input.
 */
final class RouterDispatchPathSafetyTest extends TestCase
{
    #[Test]
    public function protocolRelativePathDoesNotMatchRegisteredRoute(): void
    {
        $router = new Router();
        $router->get('/foo', [\stdClass::class, 'index']);

        $result = $router->dispatch('GET', '//evil.com/foo');

        $this->assertTrue(
            $result->isErr(),
            'protocol-relative path `//evil.com/foo` must NOT be silently normalized to `/foo`; '
            . 'parse_url() did that — the explicit normalizer must reject it as 404.',
        );
    }

    #[Test]
    public function protocolRelativePathYieldsNoAllowedMethods(): void
    {
        $router = new Router();
        $router->get('/foo', [\stdClass::class, 'index']);
        $router->post('/foo', [\stdClass::class, 'index']);

        $methods = $router->getAllowedMethods('//evil.com/foo');

        $this->assertSame(
            [],
            $methods,
            'getAllowedMethods() must not leak which methods exist on `/foo` for a host-bearing URI.',
        );
    }

    #[Test]
    public function ordinaryPathStillMatches(): void
    {
        $router = new Router();
        $router->get('/foo', [\stdClass::class, 'index']);

        $this->assertTrue(
            $router->dispatch('GET', '/foo')->isOk(),
            'ordinary `/foo` path must still resolve normally.',
        );
    }

    #[Test]
    public function pathWithQueryStringStillMatches(): void
    {
        $router = new Router();
        $router->get('/foo', [\stdClass::class, 'index']);

        $this->assertTrue(
            $router->dispatch('GET', '/foo?a=1&b=2')->isOk(),
            'query strings must be stripped before matching, not change route resolution.',
        );
    }
}
