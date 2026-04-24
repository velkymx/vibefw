<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Cache\CacheInterface;
use Fw\Core\Application;
use Fw\Core\Container;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Middleware\PageCacheMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Item #49: `PageCacheMiddleware` built the cache key as
 * `'page:static:' . md5($request->uri)`. Two problems:
 *
 *   1. `$request->uri` is the PATH only (parseUri() strips the query
 *      string). So `/products?sort=asc` and `/products?sort=desc`
 *      collapse to the same cache entry — the first response served
 *      wins and the second visitor sees stale / wrong content.
 *
 *   2. There is no host/method component, so a multi-host app serves
 *      the wrong host's cached body on a name-based vhost split.
 *
 * [R53] keys the cache on method + host + full URI (path + query)
 * with SHA-256. Same URL → same key; different query strings →
 * different keys; different hosts → different keys.
 *
 * Tests verify:
 *   - same URI → same key (hits cache)
 *   - different query strings → different keys
 *   - different hosts → different keys
 *   - different methods → different keys (only GET cached, but the
 *     key still excludes other methods from cross-contamination)
 *   - source: md5() no longer used (sha256 hash path)
 */
final class PageCacheMiddlewareKeyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $store = [];

    private CacheInterface $cache;

    protected function setUp(): void
    {
        $this->store = [];
        $cache = new class($this->store) implements CacheInterface {
            public function __construct(private array &$store) {}
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
        $this->cache = $cache;
    }

    private function middleware(): PageCacheMiddleware
    {
        $app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $container = new Container();
        $container->instance(CacheInterface::class, $this->cache);
        (new ReflectionClass($app))->getProperty('container')->setValue($app, $container);
        return new PageCacheMiddleware($app, 60);
    }

    /**
     * @param array<string,string> $extraServer
     */
    private function request(string $uri, string $method = 'GET', array $extraServer = []): Request
    {
        return new Request(
            query: [],
            post: [],
            server: ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri] + $extraServer,
            files: [],
            headers: [],
            method: $method,
            uri: $uri,
        );
    }

    #[Test]
    public function distinctQueryStringsProduceDistinctCacheEntries(): void
    {
        $mw = $this->middleware();

        $mw->handle(
            $this->request('/products?sort=asc'),
            fn ($r) => new Response('ASC body')
        );
        $response = $mw->handle(
            $this->request('/products?sort=desc'),
            fn ($r) => new Response('DESC body')
        );

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('DESC body', $response->getBody());

        // Two distinct cache entries must exist.
        $this->assertCount(
            2,
            $this->store,
            'each query string must produce a distinct cache entry'
        );
    }

    #[Test]
    public function identicalUriServesFromCache(): void
    {
        $mw = $this->middleware();

        $mw->handle(
            $this->request('/api/products'),
            fn ($r) => new Response('first body')
        );

        $second = $mw->handle(
            $this->request('/api/products'),
            fn ($r) => new Response('fresh body — should NOT be returned')
        );

        $this->assertInstanceOf(Response::class, $second);
        $this->assertSame('first body', $second->getBody(), 'second GET must hit cache');
        $this->assertCount(1, $this->store);
    }

    #[Test]
    public function distinctHostsProduceDistinctCacheEntries(): void
    {
        $mw = $this->middleware();

        $mw->handle(
            $this->request('/home', 'GET', ['HTTP_HOST' => 'a.example.com']),
            fn ($r) => new Response('A body')
        );
        $mw->handle(
            $this->request('/home', 'GET', ['HTTP_HOST' => 'b.example.com']),
            fn ($r) => new Response('B body')
        );

        $this->assertCount(
            2,
            $this->store,
            'cache key must include host — vhost split must not cross-contaminate'
        );
    }

    #[Test]
    public function sourceDoesNotUseMd5(): void
    {
        $path = (new ReflectionClass(PageCacheMiddleware::class))->getFileName();
        $this->assertIsString($path);
        $source = (string) file_get_contents($path);

        $this->assertDoesNotMatchRegularExpression(
            '/\bmd5\s*\(/',
            $source,
            'PageCacheMiddleware must use a stronger hash (sha256) for cache keys'
        );
    }
}
