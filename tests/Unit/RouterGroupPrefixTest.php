<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouterGroupPrefixTest extends TestCase
{
    public static function emptyPrefixProvider(): array
    {
        return [
            'empty string' => [''],
            'single slash' => ['/'],
        ];
    }

    private function getRegisteredPaths(Router $router): array
    {
        $ref = new \ReflectionClass($router);
        $prop = $ref->getProperty('routes');
        $routes = $prop->getValue($router);
        $paths = [];
        foreach ($routes as $methodRoutes) {
            foreach ($methodRoutes as $route) {
                $paths[] = $route['path'];
            }
        }
        return $paths;
    }

    #[Test]
    #[DataProvider('emptyPrefixProvider')]
    public function groupWithEmptyPrefixProducesNoDoubleSlash(string $prefix): void
    {
        $router = new Router();

        $router->group($prefix, function (Router $r) {
            $r->get('/foo', ['App\\Controllers\\HomeController', 'index']);
        });

        $paths = $this->getRegisteredPaths($router);
        $this->assertContains('/foo', $paths);

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('//', $path, "Path '{$path}' contains double slash");
        }

        // Route must resolve correctly
        $result = $router->dispatch('GET', '/foo');
        $this->assertTrue($result->isOk(), 'Route /foo should resolve');
    }

    #[Test]
    public function groupWithNonEmptyPrefixProducesCorrectPath(): void
    {
        $router = new Router();

        $router->group('/api', function (Router $r) {
            $r->get('/users', ['App\\Controllers\\HomeController', 'index']);
        });

        $paths = $this->getRegisteredPaths($router);
        $this->assertContains('/api/users', $paths);

        $result = $router->dispatch('GET', '/api/users');
        $this->assertTrue($result->isOk());
    }

    #[Test]
    public function nestedGroupsWithEmptyOuterPrefixProduceCorrectPath(): void
    {
        $router = new Router();

        $router->group('', function (Router $r) {
            $r->group('/v1', function (Router $r2) {
                $r2->get('/items', ['App\\Controllers\\HomeController', 'index']);
            });
        });

        $paths = $this->getRegisteredPaths($router);
        $this->assertContains('/v1/items', $paths);

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('//', $path, "Path '{$path}' contains double slash");
        }

        $result = $router->dispatch('GET', '/v1/items');
        $this->assertTrue($result->isOk());
    }
}
