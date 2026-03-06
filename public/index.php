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

require BASE_PATH . '/vendor/autoload.php';

use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Core\SapiEmitter;

// 1. Initialize Application (Boots only once in Worker Mode)
$app = Application::getInstance();
$kernel = $app->getKernel();
$emitter = new SapiEmitter();

// 2. Define the Request Handler
$handler = static function () use ($kernel, $emitter) {
    // In FrankenPHP, superglobals are populated per-request inside the worker loop
    $request = Request::createFromGlobals();
    $response = $kernel->handle($request);
    $emitter->emit($response);
};

// 3. Execution Loop
if (function_exists('frankenphp_handle_request')) {
    // FrankenPHP Worker Mode
    // This loop continues until the server signals a shutdown
    while (frankenphp_handle_request($handler)) {
        // Optional: Perform any cross-request maintenance here if needed
    }
} else {
    // Standard PHP-FPM / CLI-Server Mode
    $handler();
}
