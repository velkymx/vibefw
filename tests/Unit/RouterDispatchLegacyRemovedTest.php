<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\RouteMatch;
use Fw\Core\Router;
use Fw\Lifecycle\Component;
use Fw\Support\Result;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouterDispatchLegacyStubController extends Component
{
    public function render(): string
    {
        return '';
    }
}

final class RouterDispatchLegacyRemovedTest extends TestCase
{
    #[Test]
    public function dispatchLegacyMethodIsGone(): void
    {
        $this->assertFalse(
            method_exists(Router::class, 'dispatchLegacy'),
            'Deprecated Router::dispatchLegacy() must be removed in the 3.0 major.',
        );
    }

    #[Test]
    public function dispatchReturnsResult(): void
    {
        $router = new Router();
        $router->get('/ping', [RouterDispatchLegacyStubController::class, 'render']);

        $result = $router->dispatch('GET', '/ping');
        $this->assertInstanceOf(Result::class, $result);
        $this->assertInstanceOf(RouteMatch::class, $result->match(
            fn (RouteMatch $m) => $m,
            fn ($e) => throw new \RuntimeException('expected ok'),
        ));
    }
}
