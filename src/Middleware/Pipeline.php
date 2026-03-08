<?php

declare(strict_types=1);

namespace Fw\Middleware;

use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Core\Response;
use InvalidArgumentException;

final class Pipeline
{
    private array $middleware = [];

    private Application $app;

    /**
     * Middleware aliases loaded from config.
     *
     * @var array<string, class-string<MiddlewareInterface>>
     */
    private array $aliases = [];

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->loadAliases();
    }

    /**
     * Register an additional middleware alias.
     */
    public function alias(string $name, string $class): self
    {
        $this->aliases[$name] = $class;
        return $this;
    }

    public function through(array $middleware): self
    {
        $this->middleware = $middleware;
        return $this;
    }

    public function then(callable $destination, Request $request): Response|string|array
    {
        $pipeline = array_reduce(
            array_reverse($this->middleware),
            $this->carry(),
            $destination
        );

        return $pipeline($request);
    }

    /**
     * Load middleware aliases from container/config.
     */
    private function loadAliases(): void
    {
        // Try to get config from container (set by MiddlewareServiceProvider)
        $config = $this->app->getContainer()->tryGet('middleware.config');

        if ($config->isSome()) {
            $this->aliases = $config->unwrap()->aliases;
            return;
        }

        // Fallback to loading directly from config
        $configFile = BASE_PATH . '/config/middleware.php';

        if (file_exists($configFile)) {
            $configData = require $configFile;
            $this->aliases = $configData['aliases'] ?? [];
        }
    }

    private function carry(): callable
    {
        return function (callable $next, string|callable|MiddlewareInterface $middleware): callable {
            return function (Request $request) use ($next, $middleware): Response|string|array {
                $instance = $this->resolve($middleware);

                return $instance->handle($request, $next);
            };
        };
    }

    private function resolve(string|callable|MiddlewareInterface $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        if (is_callable($middleware)) {
            return new CallableMiddleware($middleware);
        }

        if (is_string($middleware)) {
            return $this->resolveString($middleware);
        }

        throw new InvalidArgumentException(
            "Invalid middleware: must be a class name, callable, or MiddlewareInterface instance"
        );
    }

    /**
     * Resolve a middleware string with optional parameters.
     *
     * Formats:
     *   'auth'           -> AuthMiddleware
     *   'can:edit,post'  -> CanMiddleware with params ['edit', 'post']
     *   'Fw\Middleware\AuthMiddleware' -> Direct class instantiation
     */
    private function resolveString(string $middleware): MiddlewareInterface
    {
        [$name, $params] = $this->parseMiddleware($middleware);

        $class = $this->aliases[$name] ?? $name;

        if (!class_exists($class)) {
            throw new InvalidArgumentException("Middleware '$name' not found");
        }

        // CanMiddleware needs params as 'permissions' constructor arg
        if ($class === \Fw\Middleware\CanMiddleware::class && !empty($params)) {
            $instance = $this->app->getContainer()->make($class, [
                'permissions' => $params,
            ]);
        } else {
            $instance = $this->app->getContainer()->make($class, $params);
        }

        if (!$instance instanceof MiddlewareInterface) {
            throw new InvalidArgumentException(
                "Middleware class '$class' must implement MiddlewareInterface"
            );
        }

        return $instance;
    }

    /**
     * Parse middleware string into name and parameters.
     *
     * 'can:edit,post' -> ['can', ['edit', 'post']]
     * 'auth'          -> ['auth', []]
     */
    private function parseMiddleware(string $middleware): array
    {
        if (!str_contains($middleware, ':')) {
            return [$middleware, []];
        }

        $parts = explode(':', $middleware, 2);
        $name = $parts[0];
        $params = isset($parts[1]) ? explode(',', $parts[1]) : [];

        return [$name, $params];
    }
}
