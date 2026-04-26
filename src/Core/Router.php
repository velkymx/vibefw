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
        'any' => '[^/]+',
    ];

    private array $routes = [];

    /**
     * Lazily-built lookup index keyed as [method][segment] → list<int route-index>.
     * Segment '*' holds routes whose first path segment is dynamic (`/{slug}/…`).
     * Each inner list preserves insertion order so first-match-wins semantics
     * hold when merging the static-segment bucket with the '*' bucket.
     *
     * @var array<string, array<string, list<int>>>
     */
    private array $routeBuckets = [];

    private bool $bucketsValid = false;

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

    /**
     * Apply a middleware class to a group of routes (typed alternative to group middleware arrays).
     *
     * @param class-string $middleware Fully-qualified middleware class name
     */
    public function with(string $middleware, callable $callback): self
    {
        $this->validateMiddlewareClassString($middleware);

        $previousMiddleware = $this->groupMiddleware;
        $this->groupMiddleware = array_merge($previousMiddleware, [$middleware]);

        $callback($this);

        $this->groupMiddleware = $previousMiddleware;

        return $this;
    }

    public function group(string $prefix, callable $callback, array $middleware = []): self
    {
        foreach ($middleware as $mw) {
            if (is_string($mw)) {
                $this->validateMiddlewareClassString($mw);
            }
        }

        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $trimmed = trim($prefix, '/');
        $this->groupPrefix = $previousPrefix . ($trimmed !== '' ? '/' . $trimmed : '');
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;

        return $this;
    }

    public function middleware(string|array|callable $middleware): self
    {
        $middleware = is_array($middleware) ? $middleware : [$middleware];

        foreach ($middleware as $mw) {
            if (is_string($mw)) {
                $this->validateMiddlewareClassString($mw);
            }
        }

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
        $normalized = $this->normalizePath($uri);
        if ($normalized === null) {
            return Result::err(RouteNotFound::forRequest($method, $uri));
        }
        $uri = $normalized;

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

        foreach ($this->candidateRoutes($method, $uri) as $route) {
            if (preg_match($route['pattern'], $uri, $matches)) {
                $params = array_filter(
                    $matches,
                    fn ($key) => is_string($key),
                    ARRAY_FILTER_USE_KEY
                );

                return Result::ok(new RouteMatch(
                    handler: $route['handler'],
                    params: $params,
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
        $normalized = $this->normalizePath($uri);
        if ($normalized === null) {
            return [];
        }
        $uri = $normalized;
        $allowed = [];

        foreach ($this->routes as $method => $_) {
            foreach ($this->candidateRoutes($method, $uri) as $route) {
                if (preg_match($route['pattern'], $uri)) {
                    $allowed[] = $method;
                    break;
                }
            }
        }

        return $allowed;
    }

    public function url(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new InvalidArgumentException("Route '$name' not found");
        }

        $path = $this->namedRoutes[$name];

        $path = preg_replace_callback(
            '/\{(\w+)(?::[^}]+)?\}/',
            static function (array $m) use ($params, &$missing): string {
                $key = $m[1];
                if (!array_key_exists($key, $params)) {
                    $missing[] = $key;
                    return $m[0];
                }
                return rawurlencode((string) $params[$key]);
            },
            $path,
        );

        $missing ??= [];
        if ($missing !== []) {
            throw new InvalidArgumentException(
                "Missing required parameters for route '$name': " . implode(', ', $missing),
            );
        }

        return $path;
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * @return array<string, string> Named routes as name => path
     */
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }

    /**
     * Load routes from the cache file.
     *
     * The cache is a pure-data JSON document, never `require`'d. An attacker
     * who can write into the cache directory can at worst cause a JSON decode
     * failure and a fresh route-tree build — they cannot execute code at
     * bootstrap.
     */
    public function loadCache(): bool
    {
        if ($this->cacheFile === null || !file_exists($this->cacheFile)) {
            return false;
        }

        $content = @file_get_contents($this->cacheFile);
        if ($content === false || $content === '') {
            return false;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (!is_array($data) || !isset($data['routes'])) {
            return false;
        }

        $this->routes = $data['routes'] ?? [];
        $this->namedRoutes = $data['named'] ?? [];
        $this->bucketsValid = false;

        return true;
    }

    /**
     * Persist the compiled route table as JSON.
     *
     * Closures and object-instance handlers/middleware are filtered out
     * because they have no JSON representation; production deployments are
     * expected to use class-string handlers that round-trip cleanly.
     */
    public function saveCache(): bool
    {
        if ($this->cacheFile === null) {
            return false;
        }

        $dir = dirname($this->cacheFile);

        if (!is_dir($dir) && !mkdir($dir, 0o750, true)) {
            return false;
        }

        $serializableRoutes = [];
        $serializableNames = [];

        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $route) {
                if (!$this->isJsonSerializableHandler($route['handler'])) {
                    continue;
                }
                if (!$this->isJsonSerializableMiddlewareList($route['middleware'])) {
                    continue;
                }

                $serializableRoutes[$method][] = $route;
            }
        }

        foreach ($this->namedRoutes as $name => $path) {
            foreach ($serializableRoutes as $methodRoutes) {
                foreach ($methodRoutes as $route) {
                    if ($route['path'] === $path) {
                        $serializableNames[$name] = $path;
                        continue 3;
                    }
                }
            }
        }

        try {
            $json = json_encode(
                ['routes' => $serializableRoutes, 'named' => $serializableNames],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (\JsonException) {
            return false;
        }

        return file_put_contents($this->cacheFile, $json, LOCK_EX) !== false;
    }

    private function isJsonSerializableHandler(mixed $handler): bool
    {
        if ($handler instanceof Closure) {
            return false;
        }
        if (is_array($handler) && isset($handler[0]) && is_object($handler[0])) {
            return false;
        }
        return true;
    }

    /**
     * @param list<mixed> $middleware
     */
    private function isJsonSerializableMiddlewareList(array $middleware): bool
    {
        foreach ($middleware as $m) {
            if ($m instanceof Closure) {
                return false;
            }
            if (is_array($m) && isset($m[0]) && is_object($m[0])) {
                return false;
            }
            if (is_object($m)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Yield routes that could plausibly match $uri for $method, in registration
     * order. Draws from the URI-first-segment bucket merged with the '*' bucket
     * (dynamic-first routes) so far fewer patterns are tested than a linear scan.
     *
     * @return iterable<array<string, mixed>>
     */
    private function candidateRoutes(string $method, string $uri): iterable
    {
        if (!isset($this->routes[$method])) {
            return;
        }

        if (!$this->bucketsValid) {
            $this->rebuildBuckets();
        }

        $segment = $this->firstUriSegment($uri);
        $static = $this->routeBuckets[$method][$segment] ?? [];
        $dynamic = $this->routeBuckets[$method]['*'] ?? [];

        // Merge two insertion-ordered index lists into one ordered stream.
        $routes = $this->routes[$method];
        $i = 0;
        $j = 0;
        $staticCount = count($static);
        $dynamicCount = count($dynamic);
        while ($i < $staticCount || $j < $dynamicCount) {
            if ($j >= $dynamicCount || ($i < $staticCount && $static[$i] < $dynamic[$j])) {
                yield $routes[$static[$i++]];
            } else {
                yield $routes[$dynamic[$j++]];
            }
        }
    }

    private function rebuildBuckets(): void
    {
        $this->routeBuckets = [];
        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $index => $route) {
                $key = $this->bucketKeyForPath($route['path']);
                $this->routeBuckets[$method][$key][] = $index;
            }
        }
        $this->bucketsValid = true;
    }

    private function bucketKeyForPath(string $path): string
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return '';
        }
        $first = strtok($trimmed, '/');
        if ($first === false || str_contains($first, '{')) {
            return '*';
        }
        return $first;
    }

    /**
     * Normalize a request URI to a route-matching path.
     *
     * Strips query string and fragment so callers can pass either a raw
     * `REQUEST_URI` or a pre-extracted path. Rejects protocol-relative
     * paths (`//host/x`): `parse_url()` silently strips the host and
     * the leftover `/x` would otherwise match a registered `/x` route.
     */
    private function normalizePath(string $uri): ?string
    {
        if (str_starts_with($uri, '//')) {
            return null;
        }

        $cut = strcspn($uri, '?#');
        if ($cut < strlen($uri)) {
            $uri = substr($uri, 0, $cut);
        }

        return '/' . trim($uri, '/');
    }

    private function firstUriSegment(string $uri): string
    {
        $trimmed = trim($uri, '/');
        if ($trimmed === '') {
            return '';
        }
        $first = strtok($trimmed, '/');
        return $first === false ? '' : $first;
    }

    /**
     * Add a route to the routing table.
     *
     * @param callable|array|string $handler Can be callable, [Controller, method], or Component class name
     */
    private function addRoute(string $method, string $path, callable|array|string $handler, ?string $name): self
    {
        if ($handler instanceof Closure) {
            $suggestion = $this->suggestControllerName($method, $path);
            throw new InvalidArgumentException(
                "Closure route handlers are not allowed. Use [Controller::class, 'method'] syntax instead.\n"
                . "Route: {$method} {$path}\n"
                . "Fix: php fw make:controller {$suggestion['controller']} "
                . "then use [{$suggestion['controller']}Controller::class, '{$suggestion['method']}'] in config/routes.php"
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
        $this->bucketsValid = false;

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
     * Validate a middleware reference at registration time.
     *
     * Accepts three shapes (Pipeline does the actual resolution):
     *   - FQCN class-string (`Fw\Middleware\AuthMiddleware`)
     *   - currently-loadable class (`AuthMiddleware`)
     *   - bare alias identifier (`auth`, `page_cache`, `ability:posts:read`)
     *
     * The alias path exists so route definitions can be parameterized
     * via `name:arg1,arg2` without forcing every config file to import
     * an FQCN. The Pipeline resolves aliases against `config/middleware.php`
     * when the route is dispatched.
     */
    private function validateMiddlewareClassString(string $middleware): void
    {
        $colonPos = strpos($middleware, ':');
        $name = $colonPos !== false
            ? substr($middleware, 0, $colonPos)
            : $middleware;

        if (str_contains($name, '\\') || class_exists($name)) {
            return;
        }

        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) === 1) {
            return;
        }

        throw new InvalidArgumentException(
            "Middleware '{$middleware}' must be a class-string (e.g. AuthMiddleware::class) "
            . "or a registered alias name. Run: php fw fix to update middleware references."
        );
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

        // ReDoS guard: at most ONE unbounded quantifier per constraint.
        // Adjacent unbounded quantifiers on overlapping character classes
        // (e.g. `[a-z]+[a-z]+`, `.+.+`, `a+b+`) trigger catastrophic
        // backtracking on non-matching input. Parens are already blocked
        // above, so this is the remaining ReDoS foothold.
        //
        // Unbounded: `+`, `*`, `{n,}`, `{n,m}` where m is absent.
        // Bounded `{n,m}` with a finite upper bound cannot explode.
        // Escaped quantifiers (`\+`, `\*`) are literal and don't count.
        $unescaped = preg_replace('/\\\\./', '', $constraint) ?? '';
        $unboundedCount = preg_match_all('/[+*]|\{\d+,\}/', $unescaped);
        if ($unboundedCount > 1) {
            throw new InvalidArgumentException(
                "Route constraint for '{$paramName}' has {$unboundedCount} unbounded quantifiers " .
                "(`+`, `*`, `{n,}`) — catastrophic backtracking / ReDoS risk. " .
                "Use at most one unbounded quantifier, or use bounded `{n,m}` repetitions. " .
                "Predefined constraints: " . implode(', ', array_keys(self::SAFE_CONSTRAINTS))
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

    /**
     * Suggest a controller name and method from a route path.
     *
     * Uses the last meaningful segment as the controller name. For nested routes
     * like `/api/v1/users`, this suggests `UsersController` (from the last segment),
     * which may not match the intended controller structure. Users should manually rename
     * the generated controller if needed.
     *
     * @return array{controller: string, method: string}
     */
    private function suggestControllerName(string $method, string $path): array
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        if (empty($segments)) {
            return ['controller' => 'Home', 'method' => 'index'];
        }

        // Use the last meaningful segment as the controller name
        $name = ucfirst($segments[array_key_last($segments)]);

        $methodName = match (strtoupper($method)) {
            'GET' => 'index',
            'POST' => 'store',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'destroy',
            default => 'handle',
        };

        return ['controller' => $name, 'method' => $methodName];
    }
}
