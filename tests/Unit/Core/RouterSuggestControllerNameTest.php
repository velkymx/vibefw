<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Core\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L2: Router suggestControllerName() uses last path segment for controller name.
 *
 * Pre-fix: For a route like `/api/v1/users`, the suggestion would be
 * `UsersController` (from the last segment), which may not match the intended
 * controller structure.
 *
 * Post-fix: Added PHPDoc comment documenting the limitation and explaining
 * that users should manually rename the generated controller if needed.
 */
final class RouterSuggestControllerNameTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    #[Test]
    public function suggestControllerNameUsesLastSegment(): void
    {
        $reflection = new \ReflectionClass(Router::class);
        $suggestControllerName = $reflection->getMethod('suggestControllerName');

        // Test with nested route
        $result = $suggestControllerName->invoke($this->router, 'GET', '/api/v1/users');
        $this->assertSame('Users', $result['controller'], 'Should use last segment as controller name');
        $this->assertSame('index', $result['method'], 'Should map GET to index method');
    }

    #[Test]
    public function suggestControllerNameHandlesEmptyPath(): void
    {
        $reflection = new \ReflectionClass(Router::class);
        $suggestControllerName = $reflection->getMethod('suggestControllerName');

        // Test with empty path
        $result = $suggestControllerName->invoke($this->router, 'GET', '/');
        $this->assertSame('Home', $result['controller'], 'Should suggest Home for empty path');
        $this->assertSame('index', $result['method'], 'Should map GET to index method');
    }

    #[Test]
    public function suggestControllerNameMapsHttpMethods(): void
    {
        $reflection = new \ReflectionClass(Router::class);
        $suggestControllerName = $reflection->getMethod('suggestControllerName');

        // Test GET
        $result = $suggestControllerName->invoke($this->router, 'GET', '/posts');
        $this->assertSame('index', $result['method'], 'GET should map to index');

        // Test POST
        $result = $suggestControllerName->invoke($this->router, 'POST', '/posts');
        $this->assertSame('store', $result['method'], 'POST should map to store');

        // Test PUT/PATCH
        $result = $suggestControllerName->invoke($this->router, 'PUT', '/posts');
        $this->assertSame('update', $result['method'], 'PUT should map to update');

        $result = $suggestControllerName->invoke($this->router, 'PATCH', '/posts');
        $this->assertSame('update', $result['method'], 'PATCH should map to update');

        // Test DELETE
        $result = $suggestControllerName->invoke($this->router, 'DELETE', '/posts');
        $this->assertSame('destroy', $result['method'], 'DELETE should map to destroy');
    }

    #[Test]
    public function suggestControllerNameHasPhpDoc(): void
    {
        $source = file_get_contents((new \ReflectionClass(Router::class))->getFileName());
        $this->assertStringContainsString(
            'may not match the intended controller structure',
            $source,
            'suggestControllerName should document the limitation'
        );
    }

    #[Test]
    public function suggestControllerNameDocumentsLimitation(): void
    {
        $source = file_get_contents((new \ReflectionClass(Router::class))->getFileName());
        $this->assertStringContainsString(
            'may not match the intended controller structure',
            $source,
            'suggestControllerName should document the limitation'
        );
    }

    #[Test]
    public function suggestControllerNameDocumentsManualRename(): void
    {
        $source = file_get_contents((new \ReflectionClass(Router::class))->getFileName());
        $this->assertStringContainsString(
            'manually rename',
            $source,
            'suggestControllerName should document that users should manually rename'
        );
    }
}
