<?php

declare(strict_types=1);

return [
    // Driver: 'sync', 'file', or 'database'
    'driver' => 'file',

    // File driver settings
    'path' => BASE_PATH . '/storage/queue',

    // Database driver settings
    'table' => 'jobs',

    // Default queue name
    'default' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Allowed Job Classes (unserialize allowlist)
    |--------------------------------------------------------------------------
    |
    | Fail-closed: file/database drivers refuse to deserialize until this is
    | populated with the concrete JobInterface implementations the workers
    | are allowed to instantiate. Wildcards (`*`) and non-Job classes are
    | rejected by allowClasses() at wiring time, so misconfiguration fails
    | loudly.
    |
    | Example:
    |   'allowed_classes' => [
    |       App\Jobs\SendWelcomeEmail::class,
    |       App\Jobs\ProcessPayment::class,
    |   ],
    */
    'allowed_classes' => [],
];
