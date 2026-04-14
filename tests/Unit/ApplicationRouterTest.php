<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

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
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Core/Application.php');

        $this->assertIsString($source);
        $this->assertStringContainsString(
            '$this->router = new Router();',
            $source,
            'Application must instantiate Router with no constructor arguments.'
        );
    }

    #[Test]
    public function routerConstructorAcceptsNoArguments(): void
    {
        // Router must work without arguments (no Container dependency)
        $router = new Router();
        $this->assertInstanceOf(Router::class, $router);
    }
}
