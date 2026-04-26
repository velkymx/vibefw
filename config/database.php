<?php

declare(strict_types=1);

use Fw\Core\Env;

$driver = Env::string('DB_DRIVER', 'sqlite');

return [
    'enabled' => Env::bool('DB_ENABLED', true),
    'driver' => $driver,
    'host' => Env::string('DB_HOST', '127.0.0.1'),
    'port' => Env::int('DB_PORT', $driver === 'pgsql' ? 5432 : 3306),
    'database' => $driver === 'sqlite'
        ? BASE_PATH . '/' . Env::string('DB_DATABASE', 'storage/database.sqlite')
        : Env::string('DB_DATABASE', 'forge'),
    'username' => Env::string('DB_USERNAME', 'root'),
    'password' => Env::string('DB_PASSWORD', ''),
    'charset' => Env::string('DB_CHARSET', 'utf8mb4'),
    'collation' => Env::string('DB_COLLATION', ''),
    'logging' => Env::bool('DB_LOGGING', false),

    // Persistent connections - reuses connections across PHP-FPM workers
    // Reduces connection overhead but requires careful state management
    // For production, consider ProxySQL/PgBouncer instead
    'persistent' => Env::bool('DB_PERSISTENT', false),
];
