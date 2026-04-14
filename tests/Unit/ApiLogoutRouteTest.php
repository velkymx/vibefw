<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Router;
use Fw\Middleware\ApiAuthMiddleware;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ApiLogoutRouteTest extends TestCase
{
    #[Test]
    public function logoutRouteRequiresApiAuthentication(): void
    {
        $router = new Router();
        $routes = require BASE_PATH . '/config/routes.php';
        $routes($router);

        $match = $router->dispatch('POST', '/api/auth/logout')->unwrapOr(null);

        $this->assertNotNull($match, 'Expected /api/auth/logout to be registered');
        $this->assertContains(
            ApiAuthMiddleware::class,
            $match->middleware,
            'Logout must run behind ApiAuthMiddleware so the Bearer token is authenticated before revocation.'
        );
    }
}
