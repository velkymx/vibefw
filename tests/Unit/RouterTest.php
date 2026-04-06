<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\MethodNotAllowed;
use Fw\Core\RouteMatch;
use Fw\Core\RouteNotFound;
use Fw\Core\Router;
use Fw\Tests\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    public static function dummyAction(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
    }

    public function testGetRouteCanBeAdded(): void
    {
        $this->router->get('/users', [self::class, 'dummyAction'], 'users.index');

        $result = $this->router->dispatch('GET', '/users');

        $this->assertTrue($result->isOk());
        $match = $result->getValue();
        $this->assertInstanceOf(RouteMatch::class, $match);
        $this->assertIsCallable($match->handler);
    }

    public function testPostRouteCanBeAdded(): void
    {
        $this->router->post('/users', [self::class, 'dummyAction'], 'users.store');

        $result = $this->router->dispatch('POST', '/users');

        $this->assertTrue($result->isOk());
    }

    public function testPutRouteCanBeAdded(): void
    {
        $this->router->put('/users/{id}', [self::class, 'dummyAction']);

        $result = $this->router->dispatch('PUT', '/users/1');

        $this->assertTrue($result->isOk());
        $match = $result->getValue();
        $this->assertEquals(['1'], $match->params);
    }

    public function testDeleteRouteCanBeAdded(): void
    {
        $this->router->delete('/users/{id}', [self::class, 'dummyAction']);

        $result = $this->router->dispatch('DELETE', '/users/1');

        $this->assertTrue($result->isOk());
    }

    public function testRouteWithParameterExtractsValue(): void
    {
        $this->router->get('/users/{id}', [self::class, 'dummyAction']);

        $result = $this->router->dispatch('GET', '/users/42');

        $this->assertTrue($result->isOk());
        $match = $result->getValue();
        $this->assertEquals(['42'], $match->params);
    }

    public function testRouteWithMultipleParametersExtractsAllValues(): void
    {
        $this->router->get('/posts/{postId}/comments/{commentId}', [self::class, 'dummyAction']);

        $result = $this->router->dispatch('GET', '/posts/10/comments/5');

        $this->assertTrue($result->isOk());
        $match = $result->getValue();
        $this->assertEquals(['10', '5'], $match->params);
    }

    public function testRouteWithConstraintMatchesValidPattern(): void
    {
        $this->router->get('/users/{id:\d+}', [self::class, 'dummyAction']);

        $result = $this->router->dispatch('GET', '/users/123');

        $this->assertTrue($result->isOk());
        $match = $result->getValue();
        $this->assertEquals(['123'], $match->params);
    }

    public function testRouteWithConstraintRejectsInvalidPattern(): void
    {
        $this->router->get('/users/{id:\d+}', [self::class, 'dummyAction']);

        $result = $this->router->dispatch('GET', '/users/abc');

        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(RouteNotFound::class, $result->getError());
    }

    public function testUnmatchedRouteReturnsError(): void
    {
        $this->router->get('/users', [self::class, 'dummyAction']);

        $result = $this->router->dispatch('GET', '/posts');

        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(RouteNotFound::class, $result->getError());
    }

    public function testWrongMethodReturnsMethodNotAllowed(): void
    {
        $this->router->get('/users', [self::class, 'dummyAction']);

        $result = $this->router->dispatch('POST', '/users');

        $this->assertTrue($result->isErr());
        $error = $result->getError();
        $this->assertInstanceOf(MethodNotAllowed::class, $error);
        $this->assertContains('GET', $error->allowedMethods);
    }

    public function testHeadMethodMatchesGetRoute(): void
    {
        $this->router->get('/users', [self::class, 'dummyAction']);

        $result = $this->router->dispatch('HEAD', '/users');

        $this->assertTrue($result->isOk());
    }

    public function testNamedRouteCanGenerateUrl(): void
    {
        $this->router->get('/users/{id}', [self::class, 'dummyAction'], 'users.show');

        $url = $this->router->url('users.show', ['id' => 42]);

        $this->assertEquals('/users/42', $url);
    }

    public function testNamedRouteWithConstraintGeneratesUrl(): void
    {
        $this->router->get('/users/{id:\d+}', [self::class, 'dummyAction'], 'users.show');

        $url = $this->router->url('users.show', ['id' => 123]);

        $this->assertEquals('/users/123', $url);
    }

    public function testNamedRouteThrowsExceptionForUnknownRoute(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'unknown' not found");

        $this->router->url('unknown');
    }

    public function testNamedRouteThrowsExceptionForMissingParams(): void
    {
        $this->router->get('/users/{id}', [self::class, 'dummyAction'], 'users.show');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing required parameters");

        $this->router->url('users.show');
    }

    public function testRouteGroupAppliesPrefix(): void
    {
        $this->router->group('/api', function (Router $router) {
            $router->get('/users', [self::class, 'dummyAction']);
        });

        $result = $this->router->dispatch('GET', '/api/users');

        $this->assertTrue($result->isOk());
    }

    public function testNestedRouteGroupsApplyPrefixes(): void
    {
        $this->router->group('/api', function (Router $router) {
            $router->group('/v1', function (Router $router) {
                $router->get('/users', [self::class, 'dummyAction']);
            });
        });

        $result = $this->router->dispatch('GET', '/api/v1/users');

        $this->assertTrue($result->isOk());
    }

    public function testRouteGroupAppliesMiddleware(): void
    {
        $this->router->group('/admin', function (Router $router) {
            $router->get('/dashboard', [self::class, 'dummyAction']);
        }, ['auth']);

        $result = $this->router->dispatch('GET', '/admin/dashboard');

        $this->assertTrue($result->isOk());
        $match = $result->getValue();
        $this->assertContains('auth', $match->middleware);
    }

    public function testNestedRouteGroupsMergeMiddleware(): void
    {
        $this->router->group('/api', function (Router $router) {
            $router->group('/admin', function (Router $router) {
                $router->get('/users', [self::class, 'dummyAction']);
            }, ['admin']);
        }, ['auth']);

        $result = $this->router->dispatch('GET', '/api/admin/users');

        $this->assertTrue($result->isOk());
        $match = $result->getValue();
        $this->assertContains('auth', $match->middleware);
        $this->assertContains('admin', $match->middleware);
    }

    public function testMiddlewareCanBeAddedToSingleRoute(): void
    {
        $this->router->get('/admin', [self::class, 'dummyAction'])
            ->middleware('auth');

        $result = $this->router->dispatch('GET', '/admin');

        $this->assertTrue($result->isOk());
        $match = $result->getValue();
        $this->assertContains('auth', $match->middleware);
    }

    public function testMultipleMiddlewareCanBeAddedToSingleRoute(): void
    {
        $this->router->get('/admin', [self::class, 'dummyAction'])
            ->middleware(['auth', 'verified']);

        $result = $this->router->dispatch('GET', '/admin');

        $this->assertTrue($result->isOk());
        $match = $result->getValue();
        $this->assertContains('auth', $match->middleware);
        $this->assertContains('verified', $match->middleware);
    }

    public function testMiddlewareAliasesCanBeRegistered(): void
    {
        $this->router->aliasMiddleware('auth', 'Fw\Middleware\AuthMiddleware');

        $resolved = $this->router->resolveMiddleware('auth');

        $this->assertEquals('Fw\Middleware\AuthMiddleware', $resolved);
    }

    public function testUnaliasedMiddlewarePassesThrough(): void
    {
        $resolved = $this->router->resolveMiddleware('SomeMiddleware');

        $this->assertEquals('SomeMiddleware', $resolved);
    }

    public function testGlobalMiddlewareCanBePushed(): void
    {
        $this->router->pushMiddleware('Fw\Middleware\CorsMiddleware');

        $middleware = $this->router->getGlobalMiddleware();

        $this->assertContains('Fw\Middleware\CorsMiddleware', $middleware);
    }

    public function testAnyRouteMatchesAllMethods(): void
    {
        $this->router->any('/webhook', [self::class, 'dummyAction']);

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $result = $this->router->dispatch($method, '/webhook');
            $this->assertTrue($result->isOk(), "Failed for method: $method");
        }
    }

    public function testMatchRouteMatchesSpecifiedMethods(): void
    {
        $this->router->match(['GET', 'POST'], '/resource', [self::class, 'dummyAction']);

        $this->assertTrue($this->router->dispatch('GET', '/resource')->isOk());
        $this->assertTrue($this->router->dispatch('POST', '/resource')->isOk());
        $this->assertTrue($this->router->dispatch('DELETE', '/resource')->isErr());
    }

    public function testDispatchHandlesQueryStrings(): void
    {
        $this->router->get('/search', [self::class, 'dummyAction']);

        $result = $this->router->dispatch('GET', '/search?q=test&page=1');

        $this->assertTrue($result->isOk());
    }

    public function testDispatchMatchesTrailingSlashes(): void
    {
        $this->router->get('/users', [self::class, 'dummyAction']);

        $result = $this->router->dispatch('GET', '/users/');

        // Router normalizes trailing slashes and matches
        $this->assertTrue($result->isOk());
    }

    public function testControllerArrayHandlerIsPreserved(): void
    {
        $handler = ['UserController', 'index'];
        $this->router->get('/users', $handler);

        $result = $this->router->dispatch('GET', '/users');

        $this->assertTrue($result->isOk());
        $match = $result->getValue();
        $this->assertEquals($handler, $match->handler);
    }

    public function testGetRoutesReturnsAllRoutes(): void
    {
        $this->router->get('/get', [self::class, 'dummyAction']);
        $this->router->post('/post', [self::class, 'dummyAction']);

        $routes = $this->router->getRoutes();

        $this->assertArrayHasKey('GET', $routes);
        $this->assertArrayHasKey('POST', $routes);
        $this->assertCount(1, $routes['GET']);
        $this->assertCount(1, $routes['POST']);
    }

    public function testGetAllowedMethodsReturnsCorrectMethods(): void
    {
        $this->router->get('/users', [self::class, 'dummyAction']);
        $this->router->post('/users', [self::class, 'dummyAction']);
        $this->router->put('/users/{id}', [self::class, 'dummyAction']);

        $methods = $this->router->getAllowedMethods('/users');

        $this->assertContains('GET', $methods);
        $this->assertContains('POST', $methods);
        $this->assertNotContains('PUT', $methods); // Different path pattern
    }

    public function testRouteMatchIsComponent(): void
    {
        // Test helper methods on RouteMatch
        $match = new RouteMatch(
            handler: fn() => 'test',
            params: ['id' => '1'],
            middleware: ['auth'],
        );

        $this->assertFalse($match->isComponent());
        $this->assertFalse($match->isController());
        $this->assertTrue($match->isCallable());
    }

    public function testRouteMatchIsController(): void
    {
        $match = new RouteMatch(
            handler: ['UserController', 'index'],
            params: [],
            middleware: [],
        );

        $this->assertFalse($match->isComponent());
        $this->assertTrue($match->isController());
        // Note: isCallable returns false for string class names that don't exist
        // but the isController check is what matters for routing
    }
}
