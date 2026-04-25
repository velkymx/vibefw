<?php

declare(strict_types=1);

namespace Fw\Middleware;

use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Core\Response;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;

final class Pipeline
{
    /**
     * Static cache for file-loaded aliases so the config file is only
     * require'd once per process, regardless of how many Pipeline instances
     * are created.  null = not yet loaded.
     *
     * @var array<string, class-string<MiddlewareInterface>>|null
     */
    private static ?array $cachedFileAliases = null;

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
     * Reset the file-alias static cache.  Call this in tests or after
     * config changes to force a fresh load on the next instantiation.
     */
    public static function clearAliasCache(): void
    {
        self::$cachedFileAliases = null;
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

        $config->match(
            some: function ($cfg): void {
                $this->aliases = $cfg->aliases;
            },
            none: fn () => null,
        );

        if ($config->isSome()) {
            return;
        }

        // Fallback: read config file, but cache the result so it is only
        // require'd once per process (static cache survives across instances).
        if (self::$cachedFileAliases !== null) {
            $this->aliases = self::$cachedFileAliases;
            return;
        }

        $configFile = BASE_PATH . '/config/middleware.php';

        if (file_exists($configFile)) {
            $configData = require $configFile;
            self::$cachedFileAliases = $configData['aliases'] ?? [];
        } else {
            self::$cachedFileAliases = [];
        }

        $this->aliases = self::$cachedFileAliases;
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
     *   'auth'                         -> AuthMiddleware
     *   'can:edit,post'                -> CanMiddleware($ability='edit', $model='post')
     *   'page_cache:300'               -> PageCacheMiddleware($ttl='300')
     *   'ability:posts:read,posts:write' -> TokenAbilityMiddleware($abilities='posts:read,posts:write')
     *   'Fw\Middleware\AuthMiddleware' -> Direct class instantiation
     */
    private function resolveString(string $middleware): MiddlewareInterface
    {
        [$name, $rawParams] = $this->parseMiddleware($middleware);

        $class = $this->aliases[$name] ?? $name;

        if (!class_exists($class)) {
            throw new InvalidArgumentException("Middleware '$name' not found");
        }

        $args = $this->bindUserParams($class, $rawParams);
        $instance = $this->app->getContainer()->make($class, $args);

        if (!$instance instanceof MiddlewareInterface) {
            throw new InvalidArgumentException(
                "Middleware class '$class' must implement MiddlewareInterface"
            );
        }

        return $instance;
    }

    /**
     * Split `name:rest` once. Everything after the first colon is the raw
     * argument string — caller decides whether to comma-split it.
     *
     * 'can:edit,post' -> ['can', 'edit,post']
     * 'auth'          -> ['auth', '']
     */
    private function parseMiddleware(string $middleware): array
    {
        if (!str_contains($middleware, ':')) {
            return [$middleware, ''];
        }

        [$name, $rest] = explode(':', $middleware, 2);
        return [$name, $rest];
    }

    /**
     * Map the raw post-colon string onto the middleware constructor's
     * non-typed parameters by name.
     *
     * Container::make() resolves typed (class) parameters via auto-wiring,
     * so we only fill the remaining string/scalar parameters here. When
     * the middleware declares exactly one user-provided parameter, the
     * raw string is passed through whole — that preserves embedded
     * commas/colons (e.g. `ability:posts:read,posts:write` reaches
     * TokenAbilityMiddleware as `posts:read,posts:write`). When it
     * declares N, the raw string is comma-split into N tokens.
     *
     * @return array<string, string>
     */
    private function bindUserParams(string $class, string $rawParams): array
    {
        $constructor = (new ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $userParamNames = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                continue;
            }
            $userParamNames[] = $param->getName();
        }

        if ($rawParams === '' || $userParamNames === []) {
            return [];
        }

        if (count($userParamNames) === 1) {
            return [$userParamNames[0] => $rawParams];
        }

        $tokens = array_map('trim', explode(',', $rawParams));
        $args = [];
        foreach ($userParamNames as $i => $paramName) {
            if (array_key_exists($i, $tokens)) {
                $args[$paramName] = $tokens[$i];
            }
        }
        return $args;
    }
}
