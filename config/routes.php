<?php

declare(strict_types=1);

use Fw\Core\Router;
use Fw\Core\Request;
use Fw\Core\Response;

/**
 * VibeFW Routes
 *
 * Define your application routes here. Run `php fw routes:list` to see all
 * registered routes.
 *
 * Quick start:
 *   php fw make:spa          — Scaffolds API routes + Vue 3 frontend
 *   php fw make:controller   — Generate a new controller
 *
 * See docs/routing.md for full documentation.
 */
return function (Router $router): void {
    // Welcome route — replace this with your own routes
    $router->get('/', function (Request $request): Response {
        return new Response(
            '<h1>Welcome to VibeFW</h1><p>Run <code>php fw make:spa</code> to scaffold a Vue 3 frontend.</p>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    });
};
