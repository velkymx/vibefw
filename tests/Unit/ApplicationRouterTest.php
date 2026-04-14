<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Application;
use Fw\Core\Router;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * H6: Application must construct Router without arguments.
 * new Router($this->container) is dead code — Router has no __construct
 * that accepts a Container. The extra arg is silently ignored by PHP.
 */
final class ApplicationRouterTest extends TestCase
{
    #[Test]
    public function applicationRouterIsProperlyInstantiated(): void
    {
        $app = Application::getInstance();
        $this->assertInstanceOf(Router::class, $app->router);
    }

    #[Test]
    public function routerConstructorAcceptsNoArguments(): void
    {
        // Router must work without arguments (no Container dependency)
        $router = new Router();
        $this->assertInstanceOf(Router::class, $router);
    }
}
