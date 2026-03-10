<?php

declare(strict_types=1);

use Fw\Middleware\ApiAuthMiddleware;
use Fw\Middleware\AuthMiddleware;
use Fw\Middleware\CanMiddleware;
use Fw\Middleware\CorsMiddleware;
use Fw\Middleware\CsrfMiddleware;
use Fw\Middleware\GuestMiddleware;
use Fw\Middleware\RateLimitMiddleware;
use Fw\Middleware\SecurityHeadersMiddleware;
use Fw\Middleware\SpaAuthMiddleware;
use Fw\Middleware\TokenAbilityMiddleware;

/**
 * Middleware Configuration
 *
 * Define middleware aliases, groups, and global middleware.
 * This replaces hardcoded middleware definitions in Pipeline.php
 * and routes.php.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Global Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware are applied to every request. Add security headers,
    | request logging, or other cross-cutting concerns here.
    |
    */
    'global' => [
        SecurityHeadersMiddleware::class,
        // Guest page cache - safe because users without session cookies
        // can't have CSRF tokens or user-specific content
        \Fw\Middleware\GuestPageCacheMiddleware::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware Aliases
    |--------------------------------------------------------------------------
    |
    | Short names for middleware classes. Use these in route definitions
    | for cleaner code: ->middleware('auth') instead of the full class name.
    |
    | Supports parameters: 'can:edit,post' passes ['edit', 'post'] to middleware.
    |
    */
    'aliases' => [
        'auth' => AuthMiddleware::class,
        'guest' => GuestMiddleware::class,
        'csrf' => CsrfMiddleware::class,
        'cors' => CorsMiddleware::class,
        'can' => CanMiddleware::class,
        'throttle' => RateLimitMiddleware::class,
        'secure' => SecurityHeadersMiddleware::class,

        // API authentication
        'api.auth' => ApiAuthMiddleware::class,
        'auth:api' => ApiAuthMiddleware::class,
        'spa.auth' => SpaAuthMiddleware::class,
        'ability' => TokenAbilityMiddleware::class,

        // Caching (opt-in only - NEVER use on pages with CSRF/session data)
        'page_cache' => \Fw\Middleware\PageCacheMiddleware::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware Groups
    |--------------------------------------------------------------------------
    |
    | Groups bundle multiple middleware under a single name. Use groups
    | for common combinations: 'web' for session/CSRF, 'api' for throttling.
    |
    | Groups can reference aliases or full class names.
    |
    */
    'groups' => [
        'web' => [
            'csrf',
        ],

        'api' => [
            'throttle',
        ],

        'authenticated' => [
            'auth',
            'csrf',
        ],
    ],
];
