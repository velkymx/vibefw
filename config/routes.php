<?php

declare(strict_types=1);

use App\Controllers\Api\Auth\LoginController;
use App\Controllers\Api\Auth\RegisterController;
use App\Controllers\Api\StatsController;
use App\Controllers\Api\UserController;
use Fw\Core\Router;
use Fw\Middleware\ApiAuthMiddleware;

return function (Router $router): void {
    $router->group('/api', function (Router $router): void {

        // Auth (public)
        $router->post('/auth/login', [LoginController::class, 'login']);
        $router->post('/auth/register', [RegisterController::class, 'register']);
        $router->post('/auth/logout', [LoginController::class, 'logout']);

        // User (protected)
        $router->get('/user', [UserController::class, 'show'])
            ->middleware(ApiAuthMiddleware::class);

        // Dashboard data (protected)
        $router->get('/dashboard/stats', [StatsController::class, 'index'])
            ->middleware(ApiAuthMiddleware::class);
    });
};
