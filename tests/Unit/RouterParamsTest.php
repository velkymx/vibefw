<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Core\Router;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * H5: Router must store named route params so RequestContext::routeParam()
 * can look them up by name. array_values() strips the keys, breaking
 * every routeParam() call silently.
 */
final class RouterParamsTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
    }

    // Stub action for valid handler registration
    public static function dummyAction(Request $request): Response
    {
        return new Response();
    }

    #[Test]
    public function dispatchStoresNamedParamsInRouteMatch(): void
    {
        $this->router->get('/posts/{id}', [self::class, 'dummyAction']);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/posts/42';

        $result = $this->router->dispatch('GET', '/posts/42');

        $this->assertTrue($result->isOk());

        $match = $result->unwrapOr(null);
        $this->assertNotNull($match);

        // params must be associative — keyed by capture group name
        $this->assertArrayHasKey('id', $match->params, 'params must contain key "id"');
        $this->assertSame('42', $match->params['id']);
    }

    #[Test]
    public function dispatchStoresMultipleNamedParams(): void
    {
        $this->router->get('/users/{userId}/posts/{postId}', [self::class, 'dummyAction']);

        $result = $this->router->dispatch('GET', '/users/7/posts/99');

        $this->assertTrue($result->isOk());

        $match = $result->unwrapOr(null);

        $this->assertArrayHasKey('userId', $match->params);
        $this->assertArrayHasKey('postId', $match->params);
        $this->assertSame('7', $match->params['userId']);
        $this->assertSame('99', $match->params['postId']);
    }

    #[Test]
    public function dispatchWithNoParamsStoresEmptyArray(): void
    {
        $this->router->get('/health', [self::class, 'dummyAction']);

        $result = $this->router->dispatch('GET', '/health');

        $this->assertTrue($result->isOk());
        $this->assertSame([], $result->unwrapOr(null)->params);
    }
}
