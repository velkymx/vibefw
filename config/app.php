<?php

declare(strict_types=1);

use Fw\Core\Env;

return [
    'name' => Env::string('APP_NAME', 'Fw Framework'),
    'env' => Env::string('APP_ENV', 'local'),
    'debug' => Env::bool('APP_DEBUG', true),
    'timezone' => Env::string('APP_TIMEZONE', 'UTC'),
    'secure_cookies' => Env::bool('APP_SECURE_COOKIES', false),

    'cors' => [
        'allowed_origins' => array_filter(array_map(
            'trim',
            explode(',', Env::string('CORS_ALLOWED_ORIGINS', '*'))
        )),
    ],
];
