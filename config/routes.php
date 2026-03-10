<?php

declare(strict_types=1);

use Fw\Auth\Auth;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Core\Router;

return function (Router $router): void {
    $router->group('/api', function (Router $router): void {

        // Auth (public)
        $router->post('/auth/login', [App\Controllers\Api\Auth\LoginController::class, 'login']);
        $router->post('/auth/register', [App\Controllers\Api\Auth\RegisterController::class, 'register']);
        $router->post('/auth/logout', [App\Controllers\Api\Auth\LoginController::class, 'logout']);

        // User (protected)
        $router->get('/user', function (Request $request): Response {
            return new Response(json_encode(Auth::user(), JSON_THROW_ON_ERROR), 200)
                ->header('Content-Type', 'application/json');
        })->middleware('auth:api');

        // Dashboard data (protected)
        $router->get('/dashboard/stats', [App\Controllers\Api\StatsController::class, 'index'])
            ->middleware('auth:api');
    });
};
