<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Middleware;

use Fw\Cache\CacheInterface;
use Fw\Cache\MemoryCache;
use Fw\Core\Application;
use Fw\Core\Container;
use Fw\Core\Router;
use Fw\Middleware\CanMiddleware;
use Fw\Middleware\PageCacheMiddleware;
use Fw\Middleware\Pipeline;
use Fw\Middleware\TokenAbilityMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Item #8: Parameterized middleware (`ability:posts:read`,
 * `page_cache:300`, `can:edit,post`) must work end-to-end.
 *
 * The two regressions:
 *   1. Router::middleware() / ::group() rejected alias-form strings
 *      because they required an FQCN.
 *   2. Pipeline only mapped CanMiddleware params correctly (and even
 *      then under the wrong constructor argname). All other
 *      parameterized middleware silently fell back to constructor
 *      defaults, turning ability checks into "authenticated only"
 *      and TTLs into the hardcoded 60s default.
 */
final class PipelineParameterizedMiddlewareTest extends TestCase
{
    #[Test]
    public function routerAcceptsAliasFormStringMiddleware(): void
    {
        $router = new Router();

        // Both calls should be no-throw — Router defers alias resolution
        // to the Pipeline instead of insisting on FQCN at registration.
        $router->group('/api', function (Router $r): void {
            $r->get('/x', [\stdClass::class, 'index']);
        }, ['ability:posts:read']);

        $router->get('/cached', [\stdClass::class, 'index'])
            ->middleware('page_cache:300');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function tokenAbilityMiddlewareReceivesAbilitiesString(): void
    {
        $pipeline = $this->buildPipeline([
            'ability' => TokenAbilityMiddleware::class,
        ]);

        $resolve = new ReflectionMethod(Pipeline::class, 'resolveString');
        /** @var TokenAbilityMiddleware $instance */
        $instance = $resolve->invoke($pipeline, 'ability:posts:read,posts:write');

        $this->assertInstanceOf(TokenAbilityMiddleware::class, $instance);

        $abilities = (new ReflectionClass($instance))->getProperty('abilities')->getValue($instance);
        $this->assertSame(
            'posts:read,posts:write',
            $abilities,
            'Pipeline must hand TokenAbilityMiddleware the full comma-joined ability string so the middleware\'s own parser can split it.',
        );
    }

    #[Test]
    public function pageCacheMiddlewareReceivesTtlParameter(): void
    {
        $pipeline = $this->buildPipeline([
            'page_cache' => PageCacheMiddleware::class,
        ]);

        $resolve = new ReflectionMethod(Pipeline::class, 'resolveString');
        /** @var PageCacheMiddleware $instance */
        $instance = $resolve->invoke($pipeline, 'page_cache:300');

        $this->assertInstanceOf(PageCacheMiddleware::class, $instance);

        $ttl = (new ReflectionClass($instance))->getProperty('ttl')->getValue($instance);
        $this->assertSame(
            300,
            $ttl,
            'PageCacheMiddleware must receive the parsed TTL, not fall back to the 60-second default.',
        );
    }

    #[Test]
    public function canMiddlewareReceivesAbilityAndModelByPosition(): void
    {
        $pipeline = $this->buildPipeline([
            'can' => CanMiddleware::class,
        ]);

        $resolve = new ReflectionMethod(Pipeline::class, 'resolveString');
        /** @var CanMiddleware $instance */
        $instance = $resolve->invoke($pipeline, 'can:edit,post');

        $this->assertInstanceOf(CanMiddleware::class, $instance);

        $reflection = new ReflectionClass($instance);
        $this->assertSame('edit', $reflection->getProperty('ability')->getValue($instance));
        $this->assertSame('post', $reflection->getProperty('model')->getValue($instance));
    }

    private function buildPipeline(array $aliases): Pipeline
    {
        $container = new Container();
        $app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();

        $appRef = new ReflectionClass($app);
        $appRef->getProperty('container')->setValue($app, $container);

        // Bind Application back into its container so middleware that
        // declare `Application $app` get auto-injected.
        $container->instance(Application::class, $app);
        $container->instance(CacheInterface::class, new MemoryCache());

        $pipeline = new Pipeline($app);
        $pipelineRef = new ReflectionClass($pipeline);
        $pipelineRef->getProperty('aliases')->setValue($pipeline, $aliases);

        return $pipeline;
    }
}
