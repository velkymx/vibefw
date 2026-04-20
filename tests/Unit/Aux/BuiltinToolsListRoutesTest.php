<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\BuiltinTools;
use Fw\Aux\Http\RouteAdvertiser;
use Fw\Core\Router;
use PHPUnit\Framework\TestCase;

final class BuiltinToolsListRoutesTest extends TestCase
{
    public function testToolHasNameAndRoutesReadAbility(): void
    {
        $router = new Router();
        $tool = BuiltinTools::listRoutes(new RouteAdvertiser($router));

        $this->assertSame('list_routes', $tool->name);
        $this->assertContains('routes:read', $tool->abilities);
        $this->assertTrue($tool->annotations['readOnlyHint'] ?? false);
    }

    public function testHandlerReturnsAdvertisedRoutes(): void
    {
        $router = new Router();
        $router->get('/posts', ['App\\Controllers\\StubController', 'index'], 'posts.index');

        $tool = BuiltinTools::listRoutes(new RouteAdvertiser($router));
        $result = ($tool->handler)([]);

        $this->assertFalse($result->isError);
        $this->assertCount(1, $result->completed);
        $this->assertSame('posts.index', $result->completed[0]['name']);
    }
}
