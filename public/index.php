<?php

declare(strict_types=1);

// Serve static files directly when using PHP's built-in server
if (php_sapi_name() === 'cli-server') {
    $path = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($path) && !str_ends_with($path, '.php')) {
        return false;
    }
}

define('BASE_PATH', dirname(__DIR__));

// Only start session when needed (has session cookie or state-changing request)
if (session_status() === PHP_SESSION_NONE) {
    $hasSessionCookie = isset($_COOKIE[session_name()]);
    $isStateChanging = in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true);

    if ($hasSessionCookie || $isStateChanging) {
        session_start();
    }
}

require BASE_PATH . '/vendor/autoload.php';

use Fw\Core\Application;

$app = Application::getInstance();
$app->run();
