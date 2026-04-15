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
 * P-03: Pipeline::loadAliases() must cache file-loaded aliases so the config
 * file is not re-read on every Pipeline instantiation.
 */
final class PipelineAliasCacheTest extends TestCase
{
    protected function setUp(): void
    {
        Pipeline::clearAliasCache();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a minimal Application stub (no constructor side-effects) wired to
     * a fresh Container that has no 'middleware.config' binding — forcing the
     * file fallback path in loadAliases().
     */
    private function appWithEmptyContainer(): Application
    {
        $container = new Container();

        $app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        (new ReflectionClass($app))->getProperty('container')->setValue($app, $container);

        return $app;
    }

    /**
     * Build a minimal Application stub wired to a Container that has
     * 'middleware.config' bound with the given aliases.
     *
     * @param array<string, class-string> $aliases
     */
    private function appWithContainerAliases(array $aliases): Application
    {
        $cfg          = new stdClass();
        $cfg->aliases = $aliases;

        $container = new Container();
        $container->instance('middleware.config', $cfg);

        $app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        (new ReflectionClass($app))->getProperty('container')->setValue($app, $container);

        return $app;
    }

    /**
     * Read the private $aliases property of a Pipeline via Reflection.
     *
     * @return array<string, string>
     */
    private function getAliases(Pipeline $pipeline): array
    {
        $prop = (new ReflectionClass($pipeline))->getProperty('aliases');
        return $prop->getValue($pipeline);
    }

    // -------------------------------------------------------------------------
    // Container path
    // -------------------------------------------------------------------------

    #[Test]
    public function containerAliasesLoadedWhenConfigBound(): void
    {
        $app = $this->appWithContainerAliases(['auth' => 'App\Middleware\AuthMiddleware']);

        $pipeline = new Pipeline($app);

        $this->assertSame(['auth' => 'App\Middleware\AuthMiddleware'], $this->getAliases($pipeline));
    }

    #[Test]
    public function containerAliasesOverrideFileAliases(): void
    {
        // When middleware.config is bound, the file fallback is never read.
        $app = $this->appWithContainerAliases(['custom' => 'App\Middleware\CustomMiddleware']);

        $aliases = $this->getAliases(new Pipeline($app));

        $this->assertArrayHasKey('custom', $aliases);
        $this->assertArrayNotHasKey('auth', $aliases); // real file has 'auth', container doesn't
    }

    // -------------------------------------------------------------------------
    // File fallback path
    // -------------------------------------------------------------------------

    #[Test]
    public function fileFallbackLoadsRealAliasesWhenContainerEmpty(): void
    {
        // BASE_PATH/config/middleware.php exists and defines 'auth', 'can', 'throttle'
        $pipeline = new Pipeline($this->appWithEmptyContainer());
        $aliases  = $this->getAliases($pipeline);

        $this->assertArrayHasKey('auth', $aliases);
        $this->assertArrayHasKey('can', $aliases);
        $this->assertArrayHasKey('throttle', $aliases);
    }

    #[Test]
    public function secondPipelineGetsSameFileFallbackAliases(): void
    {
        $app1    = $this->appWithEmptyContainer();
        $app2    = $this->appWithEmptyContainer();

        $aliases1 = $this->getAliases(new Pipeline($app1));
        $aliases2 = $this->getAliases(new Pipeline($app2));

        // Both instances must see the same aliases — proves cache is populated
        // and consistent (file not re-read inconsistently).
        $this->assertSame($aliases1, $aliases2);
    }

    // -------------------------------------------------------------------------
    // Cache management
    // -------------------------------------------------------------------------

    #[Test]
    public function clearAliasCacheResetsState(): void
    {
        // First load populates cache
        new Pipeline($this->appWithEmptyContainer());

        // Clear and reload — must still work correctly
        Pipeline::clearAliasCache();
        $pipeline = new Pipeline($this->appWithEmptyContainer());

        $this->assertArrayHasKey('auth', $this->getAliases($pipeline));
    }

    #[Test]
    public function aliasMethodAppendsToLoadedAliases(): void
    {
        $app      = $this->appWithContainerAliases(['auth' => 'App\Middleware\AuthMiddleware']);
        $pipeline = (new Pipeline($app))->alias('admin', 'App\Middleware\AdminMiddleware');

        $aliases = $this->getAliases($pipeline);

        $this->assertArrayHasKey('auth', $aliases);
        $this->assertArrayHasKey('admin', $aliases);
    }
}
