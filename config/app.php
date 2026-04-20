<?php

declare(strict_types=1);

use Fw\Core\Env;
use Fw\Core\Response;

return [
    'name' => Env::string('APP_NAME', 'VibeFw Framework'),
    'env' => Env::string('APP_ENV', 'local'),
    'debug' => Env::bool('APP_DEBUG', true),
    'timezone' => Env::string('APP_TIMEZONE', 'UTC'),
    'secure_cookies' => Env::bool('APP_SECURE_COOKIES', false),
    'session_same_site' => Env::string('SESSION_SAME_SITE', 'Strict'),

    'cors' => [
        'allowed_origins' => array_filter(array_map(
            'trim',
            explode(',', Env::string('CORS_ALLOWED_ORIGINS', '*'))
        )),
    ],

    'security' => [
        'csp' => Env::string('APP_CSP', Response::DEFAULT_CSP),
    ],
];
