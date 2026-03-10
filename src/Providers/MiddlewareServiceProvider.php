<?php

declare(strict_types=1);

namespace Fw\Providers;

use Fw\Core\Router;
use Fw\Core\ServiceProvider;
use Fw\Middleware\MiddlewareInterface;
use stdClass;

/**
 * Framework Middleware Service Provider.
 *
 * Loads middleware configuration from config/middleware.php
 * and registers aliases, groups, and global middleware.
 *
 * Configuration format:
 *     return [
 *         'global' => [SecurityHeadersMiddleware::class],
 *         'aliases' => [
 *             'auth' => AuthMiddleware::class,
 *             'csrf' => CsrfMiddleware::class,
 *         ],
 *         'groups' => [
 *             'web' => ['csrf'],
 *             'api' => ['throttle'],
 *         ],
 *     ];
 */
class MiddlewareServiceProvider extends ServiceProvider
{
    /**
     * Global middleware applied to all routes.
     *
     * @var list<class-string<MiddlewareInterface>|string>
     */
    protected array $global = [];

    /**
     * Middleware aliases for short names.
     *
     * @var array<string, class-string<MiddlewareInterface>>
     */
    protected array $aliases = [];

    /**
     * Middleware groups for applying multiple at once.
     *
     * @var array<string, list<string>>
     */
    protected array $groups = [];

    public function register(): void
    {
        $this->loadConfiguration();
    }

    public function boot(): void
    {
        $router = $this->container->get(Router::class);

        // Register global middleware
        foreach ($this->global as $middleware) {
            $router->pushMiddleware($middleware);
        }

        // Register aliases
        foreach ($this->aliases as $alias => $class) {
            $router->aliasMiddleware($alias, $class);
        }

        // Register groups
        foreach ($this->groups as $name => $middleware) {
            $router->middlewareGroup($name, $middleware);
        }

        // Store middleware config as stdClass wrapper for Pipeline to access
        $config = new stdClass();
        $config->aliases = $this->aliases;
        $config->groups = $this->groups;
        $this->container->instance('middleware.config', $config);
    }

    /**
     * Get global middleware.
     *
     * @return list<class-string<MiddlewareInterface>|string>
     */
    public function getGlobalMiddleware(): array
    {
        return $this->global;
    }

    /**
     * Get middleware aliases.
     *
     * @return array<string, class-string<MiddlewareInterface>>
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }

    /**
     * Get middleware groups.
     *
     * @return array<string, list<string>>
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * Load middleware configuration from file.
     */
    protected function loadConfiguration(): void
    {
        $configFile = BASE_PATH . '/config/middleware.php';

        if (!file_exists($configFile)) {
            return;
        }

        $config = require $configFile;

        if (isset($config['global'])) {
            $this->global = array_merge($this->global, $config['global']);
        }

        if (isset($config['aliases'])) {
            $this->aliases = array_merge($this->aliases, $config['aliases']);
        }

        if (isset($config['groups'])) {
            $this->groups = array_merge($this->groups, $config['groups']);
        }
    }
}
