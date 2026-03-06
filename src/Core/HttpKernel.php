<?php

declare(strict_types=1);

namespace Fw\Core;

use Fw\Async\EventLoop;
use Fw\Cache\CacheInterface;
use Fw\Events\EventDispatcher;
use Fw\Lifecycle\Component;
use Fw\Lifecycle\RequestFiber;
use Fw\Middleware\Pipeline;
use Fw\Support\ResettableInterface;
use Fw\Core\RouteMatch;

final class HttpKernel
{
    private bool $debug;
    private array $globalMiddleware;

    public function __construct(
        private Application $app,
        private Container $container,
        private Router $router,
        private EventDispatcher $events,
        private ErrorHandler $errorHandler,
        private Config $config,
    ) {
        $this->debug = (bool) $this->config->get('app.debug', false);
        $this->loadRoutes();
        $this->globalMiddleware = $this->router->getGlobalMiddleware();
    }

    /**
     * Handle the incoming request and return a Response.
     */
    public function handle(Request $request): Response
    {
        // State Integrity Check: Ensure no context leaked from previous request
        if (RequestContext::current() !== null) {
            throw new \RuntimeException('State Leak Detected');
        }

        // Early cache check
        $cacheKey = null;
        if ($cached = $this->tryGetFromCache($request, $cacheKey)) {
            return $cached;
        }

        // Create request context
        $context = RequestContext::create($request);
        $this->container->instance(RequestContext::class, $context);

        try {
            if ($this->debug) {
                $this->events->dispatch(new RequestReceived($request));
            }

            $routeResult = $this->router->dispatch($request->method, $request->uri);

            if ($routeResult->isErr()) {
                return $this->errorHandler->createRoutingResponse($routeResult->getError(), $request);
            }

            /** @var RouteMatch $match */
            $match = $routeResult->getValue();
            $context->setRouteMatch($match);

            // Execute pipeline
            $response = $this->executePipeline($request, $match);

            if ($this->debug) {
                $this->events->dispatch(new ResponseSending($response));
            }

            // Cache for guests
            if ($cacheKey !== null) {
                $this->cacheGuestResponse($cacheKey, $response->getBody());
            }

            return $response;

        } catch (\Throwable $e) {
            return $this->errorHandler->createExceptionResponse($e, $request);
        } finally {
            $this->resetState();
            RequestContext::clear();
        }
    }

    /**
     * Reset request-specific state in services.
     */
    private function resetState(): void
    {
        foreach ($this->container->getResettables() as $resettable) {
            $resettable->reset();
        }

        // Also flush the fiber-local container instances to prevent memory accumulation
        $this->container->flush();
    }

    /**
     * Try to get response from cache.
     */
    private function tryGetFromCache(Request $request, ?string &$cacheKey = null): ?Response
    {
        $cacheKey = null;

        if ($request->method !== 'GET') {
            return null;
        }

        if (isset($request->server['HTTP_COOKIE']) && str_contains($request->server['HTTP_COOKIE'], session_name())) {
            return null;
        }

        $cache = $this->container->get(CacheInterface::class);
        $normalizedUri = $this->normalizeCacheUri($request->uri);
        $key = 'page:guest:' . hash('sha256', $normalizedUri);

        $cached = $cache->get($key);

        if ($cached !== null) {
            $response = new Response($cached);
            $response->header('X-Cache', 'HIT');
            return $response;
        }

        $cacheKey = $key;
        return null;
    }

    /**
     * Cache a guest response for future requests.
     */
    private function cacheGuestResponse(string $key, string $content, int $ttl = 60): void
    {
        $cache = $this->container->get(CacheInterface::class);
        $cache->set($key, $content, $ttl);
    }

    /**
     * Normalize URI for consistent cache keys.
     */
    private function normalizeCacheUri(string $uri): string
    {
        if (str_contains($uri, "\0") || str_starts_with($uri, '//')) {
            return '/invalid-uri-' . hash('sha256', $uri);
        }

        $parsed = parse_url($uri);
        if ($parsed === false) {
            return '/malformed-uri-' . hash('sha256', $uri);
        }

        $path = strtolower($parsed['path'] ?? '/');

        // Normalize and sort query string
        $query = '';
        if (isset($parsed['query']) && $parsed['query'] !== '') {
            parse_str($parsed['query'], $params);
            $params = array_filter($params, fn($v) => $v !== '' && $v !== null && !is_array($v));
            ksort($params);

            if ($params !== []) {
                $query = '?' . http_build_query($params);
            }
        }

        return $path . $query;
    }

    /**
     * Load routes from cache or configuration file.
     */
    private function loadRoutes(): void
    {
        // Try loading from cache first
        $cacheFile = BASE_PATH . '/storage/cache/routes.php';
        $this->router->setCacheFile($cacheFile);

        if ($this->router->loadCache()) {
            return;
        }

        $routesFile = BASE_PATH . '/config/routes.php';
        if (file_exists($routesFile)) {
            $routes = require $routesFile;
            if (is_callable($routes)) {
                $routes($this->router);
            }
        }
    }

    /**
     * Execute the middleware pipeline for a route match.
     */
    private function executePipeline(Request $request, RouteMatch $match): Response
    {
        // Build middleware stack - combine pre-resolved global and local middleware
        $middleware = array_merge(
            $this->globalMiddleware,
            $this->flattenMiddleware($match->middleware)
        );

        // Resolve the handler
        $resolvedHandler = $this->resolveHandler($match->handler);

        // Destination function for middleware pipeline
        $destination = function (Request $request) use ($resolvedHandler, $match): Response {
            return $this->executeInFiber($resolvedHandler, $match->params);
        };

        // Run through middleware pipeline
        $output = (new Pipeline($this->app))
            ->through($middleware)
            ->then($destination, $request);

        // Normalize output to Response
        if ($output instanceof Response) {
            return $output;
        }

        if (is_array($output)) {
            return new Response(json_encode($output, JSON_THROW_ON_ERROR), 200);
        }

        return new Response((string) $output, 200);
    }

    /**
     * Flatten middleware array.
     */
    private function flattenMiddleware(array $middleware): array
    {
        $flattened = [];
        foreach ($middleware as $m) {
            $resolved = $this->router->resolveMiddleware($m);
            if (is_array($resolved)) {
                foreach ($resolved as $groupMiddleware) {
                    $flattened[] = $this->router->resolveMiddleware($groupMiddleware);
                }
            } else {
                $flattened[] = $resolved;
            }
        }
        return $flattened;
    }

    /**
     * Execute handler inside a Fiber.
     */
    private function executeInFiber(Component|callable $handler, array $params): Response
    {
        $loop = $this->container->get(EventLoop::class);
        $request = $this->container->get(Request::class);

        $fiber = new RequestFiber($this->app, $request, $handler, $params);
        $fiber->start();

        while (!$fiber->isCompleted()) {
            $loop->tick();
            if (!$fiber->isCompleted() && $loop->getDeferredCount() === 0 && 
                $loop->getReadStreamCount() === 0 && $loop->getWriteStreamCount() === 0 &&
                $loop->getTimerCount() === 0) {
                break;
            }
        }

        if ($fiber->getError()) {
            throw $fiber->getError();
        }

        $output = $fiber->getOutput() ?? '';
        return $output instanceof Response ? $output : new Response((string) $output, 200);
    }

    /**
     * Resolve handler to Component or callable.
     */
    private function resolveHandler(mixed $handler): Component|callable
    {
        $request = $this->container->get(Request::class);

        if (is_string($handler) && class_exists($handler) && is_subclass_of($handler, Component::class)) {
            return new $handler($this->app, $request);
        }

        if (is_array($handler)) {
            return $this->wrapControllerInLifecycle($handler);
        }

        if (is_callable($handler)) {
            return $handler;
        }

        throw new \RuntimeException('Invalid route handler');
    }

    /**
     * Wrap traditional controllers in lifecycle.
     */
    private function wrapControllerInLifecycle(array $handler): callable
    {
        return function (Request $request, ...$params) use ($handler) {
            [$class, $method] = $handler;
            if (is_string($class)) {
                $class = new $class($this->app);
            }
            return $class->$method($request, ...$params);
        };
    }
}
