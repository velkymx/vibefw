<?php

declare(strict_types=1);

use Fw\Core\Router;
use App\Controllers\Api\UserController;
use App\Controllers\Api\StatsController;
use App\Controllers\Api\ProfileController;
use App\Controllers\Api\ApiTokenController;
use App\Controllers\Api\Auth\LoginController;
use App\Controllers\Api\Auth\RegisterController;
use Fw\Middleware\ApiAuthMiddleware;

return function (Router $router): void {
    $router->group('/api', function (Router $router): void {

        // Auth (public)
        $router->post('/auth/login', [LoginController::class, 'login']);
        $router->post('/auth/register', [RegisterController::class, 'register']);
        $router->post('/auth/logout', [LoginController::class, 'logout']);

        // Protected routes
        $router->with(ApiAuthMiddleware::class, function (Router $router): void {
            $router->get('/user', [UserController::class, 'show']);
            $router->get('/dashboard/stats', [StatsController::class, 'index']);
            $router->put('/profile', [ProfileController::class, 'update']);
            $router->put('/profile/password', [ProfileController::class, 'updatePassword']);
            $router->delete('/profile', [ProfileController::class, 'destroy']);
            $router->get('/tokens', [ApiTokenController::class, 'index']);
            $router->post('/tokens', [ApiTokenController::class, 'store']);
            $router->delete('/tokens/{id}', [ApiTokenController::class, 'destroy']);
        });
    });
};