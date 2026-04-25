<?php

declare(strict_types=1);

use Fw\Core\Env;

return [
    /*
    |--------------------------------------------------------------------------
    | API Token Expiration
    |--------------------------------------------------------------------------
    |
    | Default token expiration in seconds. Set to null for non-expiring tokens.
    | Recommended: 30 days for mobile apps, shorter for high-security APIs.
    |
    */
    'token_expiration' => Env::int('API_TOKEN_EXPIRATION', 60 * 60 * 24 * 30),

    /*
    |--------------------------------------------------------------------------
    | Rate Limit (per-IP, default for RateLimitMiddleware)
    |--------------------------------------------------------------------------
    |
    | Default sliding-window limits applied by RateLimitMiddleware when no
    | explicit ctor override is supplied. Routes/providers can pin their own
    | values via the parameterized middleware string (e.g. `throttle:100,60`).
    |
    */
    'rate_limit' => [
        'max'    => Env::int('API_RATE_LIMIT_MAX', 60),
        'window' => Env::int('API_RATE_LIMIT_WINDOW', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limits (per-token tier)
    |--------------------------------------------------------------------------
    |
    | Rate limits per token tier. The key is the tier name, value is requests
    | per minute. Tokens can be assigned tiers via abilities.
    |
    */
    'rate_limits' => [
        'default' => Env::int('API_RATE_LIMIT_DEFAULT', 60),
        'standard' => Env::int('API_RATE_LIMIT_STANDARD', 120),
        'premium' => Env::int('API_RATE_LIMIT_PREMIUM', 300),
        'unlimited' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Token Abilities
    |--------------------------------------------------------------------------
    |
    | List of valid token abilities (scopes). Tokens can only be created with
    | abilities from this list. Use hierarchical format for fine-grained control.
    |
    */
    'abilities' => [
        '*', // Full access (superuser — admin only, not self-service)
        'read', // Read-only access to all resources
        'write', // Write access to all resources

        // Posts
        'posts:read', // Read posts
        'posts:write', // Create/update posts
        'posts:delete', // Delete posts

        // Users
        'users:read', // Read user data
        'users:write', // Update user data (admin only)

        // Rate limit tiers (assigned as abilities — admin only)
        'tier:standard',
        'tier:premium',
        'tier:unlimited',
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-Service Token Abilities
    |--------------------------------------------------------------------------
    |
    | Abilities that a non-admin user can assign to their own tokens via the
    | self-service endpoint. Anything NOT in this list is silently stripped.
    | Admin tokens (created by admins out-of-band) are not constrained here.
    |
    */
    'self_service_abilities' => [
        'read',
        'write',
        'posts:read',
        'posts:write',
        'posts:delete',
    ],

    /*
    |--------------------------------------------------------------------------
    | SPA Domains Whitelist
    |--------------------------------------------------------------------------
    |
    | Domains allowed to make SPA (cookie-based) authenticated requests.
    | These domains bypass token auth and use session + CSRF protection.
    | Include both with and without 'www' prefix if needed.
    |
    */
    'spa_domains' => Env::array('API_SPA_DOMAINS', ['localhost', '127.0.0.1']),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for generated tokens. Helps identify token type at a glance.
    | The format will be: {prefix}{user_id}|{random_hex}
    |
    */
    'token_prefix' => Env::string('API_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Hash Algorithm
    |--------------------------------------------------------------------------
    |
    | Algorithm used to hash tokens before storage.
    | SHA-256 is recommended for performance and security balance.
    |
    */
    'hash_algo' => Env::string('API_HASH_ALGO', 'sha256'),
];
