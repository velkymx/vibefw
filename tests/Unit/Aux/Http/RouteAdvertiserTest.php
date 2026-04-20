<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux\Http;

use Fw\Aux\Http\RouteAdvertiser;
use Fw\Core\Router;
use Fw\Middleware\AuthMiddleware;
use Fw\Middleware\CanMiddleware;
use PHPUnit\Framework\TestCase;

final class RouteAdvertiserTest extends TestCase
{
    public function testAdvertiseReturnsNamedRoutesOnly(): void
    {
        $router = new Router();
        $router->get('/home', ['App\\Controllers\\StubController', 'handle'], 'home');
        $router->get('/unnamed', ['App\\Controllers\\StubController', 'handle']);
        $router->post('/posts', ['App\\Controllers\\StubController', 'handle'], 'posts.store');

        $advertiser = new RouteAdvertiser($router);
        $routes = $advertiser->advertise();

        $names = array_column($routes, 'name');
        $this->assertContains('home', $names);
        $this->assertContains('posts.store', $names);
        $this->assertCount(2, $routes);
    }

    public function testEachRouteIncludesMethodPathAndMiddleware(): void
    {
        $router = new Router();
        $router->group('/admin', function (Router $r) {
            $r->get('/dashboard', ['App\\Controllers\\StubController', 'handle'], 'admin.dashboard');
        }, middleware: [AuthMiddleware::class, CanMiddleware::class]);

        $advertiser = new RouteAdvertiser($router);
        $routes = $advertiser->advertise();

        $this->assertCount(1, $routes);
        $this->assertSame('admin.dashboard', $routes[0]['name']);
        $this->assertSame('GET', $routes[0]['method']);
        $this->assertSame('/admin/dashboard', $routes[0]['path']);
        $this->assertSame([AuthMiddleware::class, CanMiddleware::class], $routes[0]['middleware']);
    }

    public function testHandlerSummaryIncludesControllerAndAction(): void
    {
        $router = new Router();
        $router->get('/posts/{id}', ['App\\Controllers\\PostController', 'show'], 'posts.show');

        $advertiser = new RouteAdvertiser($router);
        $routes = $advertiser->advertise();

        $this->assertSame('App\\Controllers\\PostController@show', $routes[0]['handler']);
    }
}
