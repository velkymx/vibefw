<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Middleware;

use Fw\Cache\MemoryCache;
use Fw\Core\Application;
use Fw\Core\Config;
use Fw\Core\Container;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Middleware\Pipeline;
use Fw\Middleware\RateLimitMiddleware;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;

/**
 * Item #9: RateLimitMiddleware config knobs are no-ops.
 *
 * The middleware previously read `app.rate_limit.max/window` — keys
 * that no shipped config writes to — so `API_RATE_LIMIT_*` /
 * `AUX_RATE_LIMIT*` env overrides silently did nothing. The fix:
 *
 *   1. Default to the canonical `api.rate_limit.max/window` config.
 *   2. Accept explicit `$max` / `$window` ctor args so a route /
 *      provider can pin its own limits via the parameterized
 *      middleware string introduced in item #8 (e.g.
 *      `RateLimitMiddleware::class . ':100,60'`).
 */
final class RateLimitMiddlewareConfigTest extends TestCase
{
    #[Test]
    public function defaultsReadFromCanonicalApiConfigKeys(): void
    {
        $app = $this->buildAppWithConfig([
            'api' => [
                'rate_limit' => [
                    'max'    => 7,
                    'window' => 11,
                ],
            ],
        ]);

        $middleware = new RateLimitMiddleware($app, new MemoryCache());

        $reflection = new ReflectionClass($middleware);
        $this->assertSame(7, $reflection->getProperty('maxRequests')->getValue($middleware));
        $this->assertSame(11, $reflection->getProperty('windowSeconds')->getValue($middleware));
    }

    #[Test]
    public function explicitConstructorArgsOverrideConfig(): void
    {
        $app = $this->buildAppWithConfig([
            'api' => [
                'rate_limit' => [
                    'max'    => 60,
                    'window' => 60,
                ],
            ],
        ]);

        $middleware = new RateLimitMiddleware($app, new MemoryCache(), 5, 30);

        $reflection = new ReflectionClass($middleware);
        $this->assertSame(5, $reflection->getProperty('maxRequests')->getValue($middleware));
        $this->assertSame(30, $reflection->getProperty('windowSeconds')->getValue($middleware));
    }

    #[Test]
    public function pipelineParameterizedAliasBindsMaxAndWindow(): void
    {
        $app = $this->buildAppWithConfig([]);

        $container = $app->getContainer();
        $container->instance(Application::class, $app);
        $container->instance(\Fw\Cache\CacheInterface::class, new MemoryCache());

        $pipeline = new Pipeline($app);
        $pipelineRef = new ReflectionClass($pipeline);
        $pipelineRef->getProperty('aliases')->setValue($pipeline, [
            'throttle' => RateLimitMiddleware::class,
        ]);

        $resolve = new ReflectionMethod(Pipeline::class, 'resolveString');
        /** @var RateLimitMiddleware $instance */
        $instance = $resolve->invoke($pipeline, 'throttle:3,15');

        $this->assertInstanceOf(RateLimitMiddleware::class, $instance);

        $reflection = new ReflectionClass($instance);
        $this->assertSame(3, $reflection->getProperty('maxRequests')->getValue($instance));
        $this->assertSame(15, $reflection->getProperty('windowSeconds')->getValue($instance));
    }

    #[Test]
    public function explicitLimitGoverns429Behavior(): void
    {
        $app = $this->buildAppWithConfig([]);
        $cache = new MemoryCache();

        // max=2 / window=60: third request must trip 429.
        $middleware = new RateLimitMiddleware($app, $cache, 2, 60);

        $request = new Request();
        $next = static fn (Request $r): Response => (new Response())->setStatus(200);

        $first  = $middleware->handle($request, $next);
        $second = $middleware->handle($request, $next);
        $third  = $middleware->handle($request, $next);

        $this->assertInstanceOf(Response::class, $first);
        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame(429, $third->getStatusCode());
    }

    /**
     * Build a real Application skeleton with an in-memory Config repository.
     * No mocks — Config::set() drives the same lookup path as production.
     */
    private function buildAppWithConfig(array $config): Application
    {
        $app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();

        $configRepo = new Config(BASE_PATH);
        foreach ($config as $namespace => $values) {
            $configRepo->set($namespace, $values);
        }

        $appReflection = new ReflectionClass($app);
        $appReflection->getProperty('configRepository')->setValue($app, $configRepo);
        $appReflection->getProperty('response')->setValue($app, new Response());
        $appReflection->getProperty('container')->setValue($app, new Container());

        return $app;
    }
}
