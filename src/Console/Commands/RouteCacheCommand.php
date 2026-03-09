<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Command;
use Fw\Core\Router;

/**
 * Cache routes for faster route resolution.
 *
 * This pre-compiles all routes to a PHP file that can be loaded
 * directly via OPcache, bypassing the route registration process.
 */
class RouteCacheCommand extends Command
{
    protected string $name = 'route:cache';

    protected string $description = 'Create a route cache file for faster route registration';

    public function handle(): int
    {
        $cacheFile = BASE_PATH . '/storage/cache/routes.php';
        $routesFile = BASE_PATH . '/config/routes.php';

        // Create cache directory if needed
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0o755, true);
        }

        // Clear existing cache first
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }

        // Create a fresh router and load routes
        $router = new Router();
        $router->setCacheFile($cacheFile);

        if (!file_exists($routesFile)) {
            $this->output->error('Routes file not found: config/routes.php');
            return 1;
        }

        // Load the routes
        $routes = require $routesFile;
        if (is_callable($routes)) {
            $routes($router);
        }

        // Save to cache
        if ($router->saveCache()) {
            $routeCount = count($router->getRoutes());
            $this->output->success("Routes cached successfully! ({$routeCount} routes)");
            $this->output->line("  Cache file: {$cacheFile}");
            return 0;
        }

        $this->output->error('Failed to write route cache file');
        return 1;
    }
}
