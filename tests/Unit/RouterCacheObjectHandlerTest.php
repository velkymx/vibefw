<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouterCacheObjectHandlerTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/fw_router_test_' . uniqid() . '.php';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    #[Test]
    public function saveCacheSkipsObjectInstanceHandlers(): void
    {
        $router = new Router();
        $router->setCacheFile($this->cacheFile);

        // Normal string-based handler — should be cached
        $router->get('/normal', ['App\\Controllers\\HomeController', 'index']);

        // Object instance handler — should be silently skipped, not cause var_export to fail
        $controller = new class {
            public function index(): void {}
        };
        $router->get('/object', [$controller, 'index']);

        // Should not throw — var_export would fail on object handler
        $result = $router->saveCache();

        $this->assertTrue($result);
        $this->assertFileExists($this->cacheFile);

        // Cache file must be valid PHP
        $cached = require $this->cacheFile;
        $this->assertIsArray($cached);

        // Normal route must be present
        $this->assertArrayHasKey('GET', $cached['routes']);
        $found = false;
        foreach ($cached['routes']['GET'] as $route) {
            if ($route['path'] === '/normal') {
                $found = true;
            }
        }
        $this->assertTrue($found, '/normal route not found in cache');

        // Object-handler route must NOT be present
        foreach ($cached['routes']['GET'] ?? [] as $route) {
            $this->assertNotSame('/object', $route['path'], '/object route with object handler must be excluded');
        }
    }

    #[Test]
    public function saveCacheSkipsObjectMiddleware(): void
    {
        $router = new Router();
        $router->setCacheFile($this->cacheFile);

        $middlewareObj = new class {
            public function handle(): void {}
        };

        // Add open route normally
        $router->get('/open', ['App\\Controllers\\HomeController', 'index']);

        // Inject a route with object middleware directly (simulates group middleware set to object)
        $ref = new \ReflectionClass($router);
        $prop = $ref->getProperty('routes');
        $routes = $prop->getValue($router);
        $routes['GET'][] = [
            'method' => 'GET',
            'path' => '/guarded',
            'pattern' => '/guarded',
            'handler' => ['App\\Controllers\\HomeController', 'index'],
            'middleware' => [$middlewareObj],
        ];
        $prop->setValue($router, $routes);

        $result = $router->saveCache();
        $this->assertTrue($result);

        $cached = require $this->cacheFile;

        // /open must be cached, /guarded must be skipped
        $paths = array_column($cached['routes']['GET'] ?? [], 'path');
        $this->assertContains('/open', $paths);
        $this->assertNotContains('/guarded', $paths);
    }
}
