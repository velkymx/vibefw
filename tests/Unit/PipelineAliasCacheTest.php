<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Application;
use Fw\Core\Container;
use Fw\Middleware\Pipeline;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

/**
 * Pipeline::loadAliases() loads aliases from the container
 * (set by MiddlewareServiceProvider). No static cache or
 * file fallback — aliases are per-Pipeline-instance, loaded
 * fresh from the container on each instantiation.
 */
final class PipelineAliasCacheTest extends TestCase
{
    private function appWithContainerAliases(array $aliases): Application
    {
        $cfg = new stdClass();
        $cfg->aliases = $aliases;

        $container = new Container();
        $container->instance('middleware.config', $cfg);

        $app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        (new ReflectionClass($app))->getProperty('container')->setValue($app, $container);

        return $app;
    }

    private function appWithEmptyContainer(): Application
    {
        $container = new Container();

        $app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        (new ReflectionClass($app))->getProperty('container')->setValue($app, $container);

        return $app;
    }

    private function getAliases(Pipeline $pipeline): array
    {
        $prop = (new ReflectionClass($pipeline))->getProperty('aliases');
        return $prop->getValue($pipeline);
    }

    #[Test]
    public function containerAliasesLoadedWhenConfigBound(): void
    {
        $app = $this->appWithContainerAliases(['auth' => 'App\Middleware\AuthMiddleware']);

        $pipeline = new Pipeline($app);

        $this->assertSame(['auth' => 'App\Middleware\AuthMiddleware'], $this->getAliases($pipeline));
    }

    #[Test]
    public function emptyAliasesWhenContainerHasNoConfig(): void
    {
        $pipeline = new Pipeline($this->appWithEmptyContainer());

        $this->assertSame([], $this->getAliases($pipeline), 'Pipeline must have empty aliases when container has no middleware.config — no file fallback.');
    }

    #[Test]
    public function eachPipelineInstanceGetsFreshAliases(): void
    {
        $app1 = $this->appWithContainerAliases(['auth' => 'App\Middleware\AuthMiddleware']);
        $app2 = $this->appWithContainerAliases(['custom' => 'App\Middleware\CustomMiddleware']);

        $aliases1 = $this->getAliases(new Pipeline($app1));
        $aliases2 = $this->getAliases(new Pipeline($app2));

        $this->assertArrayHasKey('auth', $aliases1);
        $this->assertArrayNotHasKey('custom', $aliases1);
        $this->assertArrayHasKey('custom', $aliases2);
        $this->assertArrayNotHasKey('auth', $aliases2);
    }

    #[Test]
    public function aliasMethodAppendsToLoadedAliases(): void
    {
        $app = $this->appWithContainerAliases(['auth' => 'App\Middleware\AuthMiddleware']);
        $pipeline = (new Pipeline($app))->alias('admin', 'App\Middleware\AdminMiddleware');

        $aliases = $this->getAliases($pipeline);

        $this->assertArrayHasKey('auth', $aliases);
        $this->assertArrayHasKey('admin', $aliases);
    }
}
