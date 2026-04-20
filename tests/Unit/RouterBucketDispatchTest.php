<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\RouteMatch;
use Fw\Core\Router;
use Fw\Lifecycle\Component;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouterBucketDispatchStubController extends Component
{
    public function render(): string
    {
        return '';
    }
}

final class RouterBucketDispatchTest extends TestCase
{
    private function handler(string $tag): array
    {
        return [RouterBucketDispatchStubController::class, $tag];
    }

    private function unwrap(Router $router, string $method, string $uri): RouteMatch
    {
        return $router->dispatch($method, $uri)->match(
            fn (RouteMatch $m) => $m,
            fn ($e) => throw new \RuntimeException("Expected {$method} {$uri} to resolve, got error: " . (string) $e),
        );
    }

    #[Test]
    public function dispatchesStaticPathsFromCorrectSegmentBucket(): void
    {
        $router = new Router();
        $router->get('/users', $this->handler('users.index'));
        $router->get('/posts', $this->handler('posts.index'));
        $router->get('/comments', $this->handler('comments.index'));

        $this->assertSame($this->handler('users.index'), $this->unwrap($router, 'GET', '/users')->handler);
        $this->assertSame($this->handler('posts.index'), $this->unwrap($router, 'GET', '/posts')->handler);
        $this->assertSame($this->handler('comments.index'), $this->unwrap($router, 'GET', '/comments')->handler);
    }

    #[Test]
    public function dispatchesDynamicFirstSegmentViaWildcardBucket(): void
    {
        $router = new Router();
        $router->get('/users', $this->handler('users.index'));
        $router->get('/{slug}', $this->handler('pages.show'));

        $match = $this->unwrap($router, 'GET', '/about');
        $this->assertSame($this->handler('pages.show'), $match->handler);
        $this->assertSame(['slug' => 'about'], $match->params);

        // Static bucket still wins because it was registered first and matches exactly.
        $this->assertSame($this->handler('users.index'), $this->unwrap($router, 'GET', '/users')->handler);
    }

    #[Test]
    public function preservesInsertionOrderAcrossBuckets(): void
    {
        $router = new Router();
        $router->get('/{slug}', $this->handler('pages.show'));   // registered first
        $router->get('/about', $this->handler('about.static'));   // registered second — unreachable

        // First-match-wins: /{slug} matches /about before the static route.
        $match = $this->unwrap($router, 'GET', '/about');
        $this->assertSame($this->handler('pages.show'), $match->handler);
        $this->assertSame(['slug' => 'about'], $match->params);
    }

    #[Test]
    public function returnsMethodNotAllowedWhenOtherMethodMatches(): void
    {
        $router = new Router();
        $router->post('/users', $this->handler('users.store'));

        $result = $router->dispatch('GET', '/users');
        $this->assertTrue($result->isErr());
        $this->assertContains('POST', $router->getAllowedMethods('/users'));
    }

    #[Test]
    public function routesAddedAfterFirstDispatchAreReachable(): void
    {
        $router = new Router();
        $router->get('/users', $this->handler('users.index'));
        $this->unwrap($router, 'GET', '/users'); // prime any internal caches

        $router->get('/posts', $this->handler('posts.index'));
        $this->assertSame($this->handler('posts.index'), $this->unwrap($router, 'GET', '/posts')->handler);
    }

    #[Test]
    public function dispatchCorrectAmong200Routes(): void
    {
        $router = new Router();
        for ($i = 0; $i < 200; $i++) {
            $router->get("/seg{$i}/item/{id}", $this->handler("r{$i}"));
        }

        $match = $this->unwrap($router, 'GET', '/seg137/item/42');
        $this->assertSame($this->handler('r137'), $match->handler);
        $this->assertSame(['id' => '42'], $match->params);
    }
}
