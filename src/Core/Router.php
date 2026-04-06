<?php

declare(strict_types=1);

namespace Fw\Core;

use Closure;
use Fw\Lifecycle\Component;
use Fw\Support\Result;
use InvalidArgumentException;
use RuntimeException;

final class Router
{
    /**
     * Safe constraint patterns that are pre-validated.
     * These are commonly used and known to be safe from ReDoS.
     */
    private const array SAFE_CONSTRAINTS = [
        'id' => '[0-9]+',
        'slug' => '[a-z0-9-]+',
        'uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
        'alpha' => '[a-zA-Z]+',
        'alphanum' => '[a-zA-Z0-9]+',
        'any' => '.+',
    ];

    private array $routes = [];

    private array $namedRoutes = [];

    private string $groupPrefix = '';

    private array $groupMiddleware = [];

    private ?string $cacheFile = null;

    private array $globalMiddleware = [];

    private array $middlewareAliases = [];

    private array $middlewareGroups = [];

    private ?array $pendingRoute = null;

    public function setCacheFile(string $path): self
    {
        $this->cacheFile = $path;
        return $this;
    }

    public function aliasMiddleware(string $name, string $class): self
    {
        $this->middlewareAliases[$name] = $class;
        return $this;
    }

    /**
     * Register a middleware group.
     *
     * Groups allow applying multiple middleware under a single name.
     * Group middleware can reference aliases or full class names.
     *
     * @param string $name Group name
     * @param array<string> $middleware List of middleware aliases or classes
     */
    public function middlewareGroup(string $name, array $middleware): self
    {
        $this->middlewareGroups[$name] = $middleware;
        return $this;
    }

    /**
     * Get all middleware groups.
     *
     * @return array<string, array<string>>
     */
    public function getMiddlewareGroups(): array
    {
        return $this->middlewareGroups;
    }

    public function pushMiddleware(string|callable $middleware): self
    {
        $this->globalMiddleware[] = $middleware;
        return $this;
    }

    public function getGlobalMiddleware(): array
    {
        return $this->globalMiddleware;
    }

    /**
     * Resolve middleware name to class or expand group.
     *
     * @param string|callable $middleware
     * @return string|callable|array Resolved middleware or array if group
     */
    public function resolveMiddleware(string|callable $middleware): string|callable|array
    {
        if (!is_string($middleware)) {
            return $middleware;
        }

        // Check if it's a middleware group
        if (isset($this->middlewareGroups[$middleware])) {
            return $this->middlewareGroups[$middleware];
        }

        // Check if it's an alias
        if (isset($this->middlewareAliases[$middleware])) {
            return $this->middlewareAliases[$middleware];
        }

        return $middleware;
    }

    /**
     * Register a GET route.
     *
     * @param callable|array|string $handler Can be callable, [Controller, method], or Component class name
     */
    public function get(string $path, callable|array|string $handler, ?string $name = null): self
    {
        return $this->addRoute('GET', $path, $handler, $name);
    }

    /**
     * Register a POST route.
     *
     * @param callable|array|string $handler Can be callable, [Controller, method], or Component class name
     */
    public function post(string $path, callable|array|string $handler, ?string $name = null): self
    {
        return $this->addRoute('POST', $path, $handler, $name);
    }

    /**
     * Register a PUT route.
     *
     * @param callable|array|string $handler Can be callable, [Controller, method], or Component class name
     */
    public function put(string $path, callable|array|string $handler, ?string $name = null): self
    {
        return $this->addRoute('PUT', $path, $handler, $name);
    }

    /**
     * Register a PATCH route.
     *
     * @param callable|array|string $handler Can be callable, [Controller, method], or Component class name
     */
    public function patch(string $path, callable|array|string $handler, ?string $name = null): self
    {
        return $this->addRoute('PATCH', $path, $handler, $name);
    }

    /**
     * Register a DELETE route.
     *
     * @param callable|array|string $handler Can be callable, [Controller, method], or Component class name
     */
    public function delete(string $path, callable|array|string $handler, ?string $name = null): self
    {
        return $this->addRoute('DELETE', $path, $handler, $name);
    }

    /**
     * Register an OPTIONS route.
     *
     * @param callable|array|string $handler Can be callable, [Controller, method], or Component class name
     */
    public function options(string $path, callable|array|string $handler, ?string $name = null): self
    {
        return $this->addRoute('OPTIONS', $path, $handler, $name);
    }

    /**
     * Register a route for all HTTP methods.
     *
     * @param callable|array|string $handler Can be callable, [Controller, method], or Component class name
     */
    public function any(string $path, callable|array|string $handler, ?string $name = null): self
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $this->addRoute($method, $path, $handler, $name);
        }
        return $this;
    }

    /**
     * Register a route for specific HTTP methods.
     *
     * @param callable|array|string $handler Can be callable, [Controller, method], or Component class name
     */
    public function match(array $methods, string $path, callable|array|string $handler, ?string $name = null): self
    {
        foreach ($methods as $method) {
            $this->addRoute(strtoupper($method), $path, $handler, $name);
        }
        return $this;
    }

    public function group(string $prefix, callable $callback, array $middleware = []): self
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix = $previousPrefix . '/' . trim($prefix, '/');
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;

        return $this;
    }

    public function middleware(string|array|callable $middleware): self
    {
        $middleware = is_array($middleware) ? $middleware : [$middleware];

        if ($this->pendingRoute !== null) {
            $method = $this->pendingRoute['method'];
            $index = $this->pendingRoute['index'];

            $this->routes[$method][$index]['middleware'] = array_merge(
                $this->routes[$method][$index]['middleware'],
                $middleware
            );

            $this->pendingRoute = null;
        }

        return $this;
    }

    /**
     * Dispatch a request to find matching route.
     *
     * @return Result<RouteMatch, RouteNotFound|MethodNotAllowed>
     */
    public function dispatch(string $method, string $uri): Result
    {
        $uri = '/' . trim(parse_url($uri, PHP_URL_PATH) ?? '', '/');

        if ($method === 'HEAD') {
            $method = 'GET';
        }

        // First, check if this URI matches any route (regardless of method)
        $allowedMethods = $this->getAllowedMethods($uri);

        if (!isset($this->routes[$method])) {
            // No routes for this method at all
            if (!empty($allowedMethods)) {
                return Result::err(MethodNotAllowed::forRequest($method, $uri, $allowedMethods));
            }
            return Result::err(RouteNotFound::forRequest($method, $uri));
        }

        foreach ($this->routes[$method] as $route) {
            if (preg_match($route['pattern'], $uri, $matches)) {
                $params = array_filter(
                    $matches,
                    fn ($key) => is_string($key),
                    ARRAY_FILTER_USE_KEY
                );

                return Result::ok(new RouteMatch(
                    handler: $route['handler'],
                    params: array_values($params),
                    middleware: $route['middleware'],
                ));
            }
        }

        // Route not found for this method, check if other methods work
        if (!empty($allowedMethods)) {
            return Result::err(MethodNotAllowed::forRequest($method, $uri, $allowedMethods));
        }

        return Result::err(RouteNotFound::forRequest($method, $uri));
    }

    /**
     * Get allowed HTTP methods for a URI.
     *
     * @return array<string>
     */
    public function getAllowedMethods(string $uri): array
    {
        $uri = '/' . trim(parse_url($uri, PHP_URL_PATH) ?? '', '/');
        $allowed = [];

        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['pattern'], $uri)) {
                    $allowed[] = $method;
                    break;
                }
            }
        }

        return $allowed;
    }

    /**
     * Legacy dispatch that returns array or null.
     *
     * @deprecated Use dispatch() which returns Result instead
     */
    public function dispatchLegacy(string $method, string $uri): ?array
    {
        return $this->dispatch($method, $uri)->match(
            fn (RouteMatch $match) => [
                'handler' => $match->handler,
                'params' => $match->params,
                'middleware' => $match->middleware,
            ],
            fn () => null
        );
    }

    public function url(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new InvalidArgumentException("Route '$name' not found");
        }

        $path = $this->namedRoutes[$name];

        foreach ($params as $key => $value) {
            $path = preg_replace(
                '/\{' . preg_quote($key, '/') . '(?::[^}]+)?\}/',
                rawurlencode((string) $value),
                $path
            );
        }

        if (preg_match('/\{(\w+)/', $path)) {
            throw new InvalidArgumentException("Missing required parameters for route '$name'");
        }

        return $path;
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function loadCache(): bool
    {
        if ($this->cacheFile === null || !file_exists($this->cacheFile)) {
            return false;
        }

        $data = require $this->cacheFile;

        if (!is_array($data)) {
            return false;
        }

        $this->routes = $data['routes'] ?? [];
        $this->namedRoutes = $data['named'] ?? [];

        return true;
    }

    public function saveCache(): bool
    {
        if ($this->cacheFile === null) {
            return false;
        }

        $dir = dirname($this->cacheFile);

        if (!is_dir($dir) && !mkdir($dir, 0o750, true)) {
            return false;
        }

        // Filter out routes that contain closures as they cannot be serialized by var_export
        $serializableRoutes = [];
        $serializableNames = [];

        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $route) {
                // Check if handler is a closure
                if ($route['handler'] instanceof Closure) {
                    continue;
                }

                // Check if any middleware is a closure
                $hasClosureMiddleware = false;
                foreach ($route['middleware'] as $m) {
                    if ($m instanceof Closure) {
                        $hasClosureMiddleware = true;
                        break;
                    }
                }

                if ($hasClosureMiddleware) {
                    continue;
                }

                $serializableRoutes[$method][] = $route;
            }
        }

        // Only include names for routes that were actually serialized
        foreach ($this->namedRoutes as $name => $path) {
            $isSerializable = false;
            foreach ($serializableRoutes as $methodRoutes) {
                foreach ($methodRoutes as $route) {
                    if ($route['path'] === $path) {
                        $isSerializable = true;
                        break 2;
                    }
                }
            }
            if ($isSerializable) {
                $serializableNames[$name] = $path;
            }
        }

        $content = "<?php\nreturn " . var_export([
            'routes' => $serializableRoutes,
            'named' => $serializableNames,
        ], true) . ";\n";

        return file_put_contents($this->cacheFile, $content, LOCK_EX) !== false;
    }

    /**
     * Add a route to the routing table.
     *
     * @param callable|array|string $handler Can be callable, [Controller, method], or Component class name
     */
    private function addRoute(string $method, string $path, callable|array|string $handler, ?string $name): self
    {
        if ($handler instanceof Closure) {
            throw new InvalidArgumentException(
                "Closure route handlers are not allowed. Use [Controller::class, 'method'] syntax instead. "
                . "Route: {$method} {$path}"
            );
        }

        $fullPath = $this->groupPrefix . '/' . trim($path, '/');
        $fullPath = '/' . trim($fullPath, '/');

        $pattern = $this->compilePattern($fullPath);

        // Validate Component class if string handler
        if (is_string($handler) && !is_callable($handler)) {
            if (!class_exists($handler)) {
                throw new InvalidArgumentException("Handler class '$handler' does not exist");
            }
            if (!is_subclass_of($handler, Component::class)) {
                throw new InvalidArgumentException("Handler class '$handler' must extend " . Component::class);
            }
        }

        $route = [
            'method' => $method,
            'path' => $fullPath,
            'pattern' => $pattern,
            'handler' => $handler,
            'middleware' => $this->groupMiddleware,
        ];

        $this->routes[$method][] = $route;
        $index = array_key_last($this->routes[$method]);

        $this->pendingRoute = ['method' => $method, 'index' => $index];

        if ($name !== null) {
            $this->namedRoutes[$name] = $fullPath;
        }

        return $this;
    }

    private function compilePattern(string $path): string
    {
        $pattern = preg_replace_callback(
            '/\{(\w+)(?::([^}]+))?\}/',
            function (array $matches): string {
                $name = $matches[1];
                $constraint = $matches[2] ?? '[^/]+';

                // Use safe preset if available
                if (isset(self::SAFE_CONSTRAINTS[$constraint])) {
                    $constraint = self::SAFE_CONSTRAINTS[$constraint];
                } else {
                    // Validate custom constraint for ReDoS safety
                    $this->validateConstraint($constraint, $name);
                }

                return "(?P<$name>$constraint)";
            },
            $path
        );

        if ($pattern === null) {
            throw new RuntimeException("Failed to compile route pattern for path: {$path}");
        }

        return '#^' . $pattern . '$#';
    }

    /**
     * Validate a route constraint pattern for ReDoS safety.
     *
     * Uses a whitelist approach: only allows character classes, literal chars,
     * and simple quantifiers. Rejects groups, backreferences, and any construct
     * that could cause catastrophic backtracking.
     *
     * @throws InvalidArgumentException If pattern is potentially dangerous
     */
    private function validateConstraint(string $constraint, string $paramName): void
    {
        // Max length to prevent abuse
        if (strlen($constraint) > 100) {
            throw new InvalidArgumentException(
                "Route constraint for '{$paramName}' is too long (max 100 characters). " .
                "Use a predefined constraint: " . implode(', ', array_keys(self::SAFE_CONSTRAINTS))
            );
        }

        // WHITELIST approach: only allow safe regex constructs.
        // Allowed: character classes [a-z0-9], literal chars, quantifiers {n,m}/+/?/*,
        //          dot (.), anchors, simple alternation without groups.
        // Disallowed: parenthesized groups (prevents nested quantifiers entirely),
        //             backreferences, lookahead/lookbehind, recursive patterns.
        if (preg_match('/[()]/', $constraint)) {
            throw new InvalidArgumentException(
                "Route constraint for '{$paramName}' contains groups (parentheses) which are not allowed " .
                "due to ReDoS risk. Use character classes instead (e.g. [a-z0-9]+). " .
                "Predefined constraints: " . implode(', ', array_keys(self::SAFE_CONSTRAINTS))
            );
        }

        // Reject backreferences and other advanced features
        if (preg_match('/\\\\[1-9]|\\\\[pPkgG]/', $constraint)) {
            throw new InvalidArgumentException(
                "Route constraint for '{$paramName}' contains advanced regex features that are not allowed."
            );
        }

        // Test compile the pattern to ensure it's valid regex
        $testPattern = '#' . $constraint . '#';
        if (@preg_match($testPattern, '') === false) {
            throw new InvalidArgumentException(
                "Route constraint for '{$paramName}' is not a valid regular expression: {$constraint}"
            );
        }
    }
}
