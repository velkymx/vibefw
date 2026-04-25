<?php

declare(strict_types=1);

namespace Fw\Core;

use Fw\Async\EventLoop;
use Fw\Auth\ApiToken;
use Fw\Auth\EmailVerification;
use Fw\Auth\Gate;
use Fw\Auth\PasswordReset;
use Fw\Cache\CacheInterface;
use Fw\Cache\GuestCacheKey;
use Fw\Events\EventDispatcher;
use Fw\Lifecycle\Component;
use Fw\Lifecycle\RequestFiber;
use Fw\Middleware\Pipeline;
use RuntimeException;
use Throwable;

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
            throw new RuntimeException('State Leak Detected');
        }

        // Replace the boot-time Request placeholder with the live request so
        // middleware ($app->request) and container resolutions
        // (Request::class) observe the active object instead of the stale
        // boot snapshot. Must run before any cache lookup, dispatch, or
        // pipeline execution that may consult the bound request.
        $this->app->bindRequest($request);

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

            $response = $routeResult->match(
                ok: function (RouteMatch $match) use ($request, $context, &$cacheKey) {
                    $context->setRouteMatch($match);

                    $response = $this->executePipeline($request, $match);

                    if ($cacheKey !== null) {
                        $this->cacheGuestResponse($cacheKey, $response);
                    }

                    return $response;
                },
                err: fn ($error) => $this->errorHandler->createRoutingResponse($error, $request),
            );

        } catch (Throwable $e) {
            $response = $this->errorHandler->createExceptionResponse($e, $request);
        } finally {
            $this->resetState();
            RequestContext::clear();
        }

        // Handle flash data from response (satisfies pure response object requirement)
        $flash = $response->getFlash();
        if ($flash !== []) {
            $this->app->initSession();
            $_SESSION['_flash'] = array_merge($_SESSION['_flash'] ?? [], $flash);
        }

        if ($this->debug) {
            $this->events->dispatch(new ResponseSending($response));
        }

        return $response;
    }

    /**
     * Reset request-specific state in services.
     */
    private function resetState(): void
    {
        foreach ($this->container->getResettables() as $resettable) {
            $resettable->reset();
        }

        // Flush static caches that are not safe to persist between requests in
        // worker mode (FrankenPHP). Both use static properties intentionally
        // scoped to the config lifetime, but the config itself may change.
        Gate::flushCache();
        ApiToken::resetConfig();
        EmailVerification::resetConnection();
        PasswordReset::resetConnection();
        \Fw\Model\Model::resetConnection();
        // Clear the Pipeline alias cache so middleware.php changes are picked up
        // on the next request without restarting the worker process.
        Pipeline::clearAliasCache();
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
        $key = GuestCacheKey::build(
            $request->method,
            (string) $request->header('host', ''),
            $request->fullUri,
        );

        $cached = $cache->get($key);

        if (is_string($cached) && ($rehydrated = self::decodeCacheEnvelope($cached)) !== null) {
            return $rehydrated->header('X-Cache', 'HIT');
        }

        $cacheKey = $key;
        return null;
    }

    /**
     * Cache the full guest response envelope for future requests.
     *
     * Stores status, headers, and body so a cache hit replays the
     * original response. Caching only the body would silently strip
     * Content-Type/CSP/HSTS and force a 200 status on every hit.
     */
    private function cacheGuestResponse(string $key, Response $response, int $ttl = 60): void
    {
        $cache = $this->container->get(CacheInterface::class);
        $cache->set($key, self::encodeCacheEnvelope($response), $ttl);
    }

    /**
     * Encode a Response as a JSON envelope for the guest page cache.
     *
     * JSON keeps the cache payload portable, inspectable, and outside
     * the PHP object-deserialization RCE surface.
     */
    private static function encodeCacheEnvelope(Response $response): string
    {
        return json_encode([
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'body' => $response->getBody(),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Decode a cache envelope back into a Response.
     *
     * Returns null on corruption / partial entries / wrong shape so
     * the kernel falls through to a fresh pipeline run instead of
     * serving garbage.
     */
    private static function decodeCacheEnvelope(string $payload): ?Response
    {
        try {
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)
            || !isset($decoded['status'], $decoded['headers'], $decoded['body'])
            || !is_int($decoded['status'])
            || !is_array($decoded['headers'])
            || !is_string($decoded['body'])
        ) {
            return null;
        }

        return new Response($decoded['body'], $decoded['status'])->headers($decoded['headers']);
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

        // Resolve the handler against the live request so traditional
        // controllers and Components receive the active object, not the
        // boot placeholder pulled from the container.
        $resolvedHandler = $this->resolveHandler($match->handler, $request);

        // Destination function for middleware pipeline
        $destination = function (Request $request) use ($resolvedHandler, $match): Response {
            return $this->executeInFiber($resolvedHandler, $match->params, $request);
        };

        // Run through middleware pipeline
        $output = new Pipeline($this->app)
            ->through($middleware)
            ->then($destination, $request);

        if (!$output instanceof Response) {
            throw new RuntimeException(
                'Controller must return a Response object. Got: ' . get_debug_type($output)
                . '. Use $this->json() for JSON or $this->view() for HTML.'
            );
        }

        return $output;
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
    private function executeInFiber(Component|callable $handler, array $params, Request $request): Response
    {
        $loop = $this->container->get(EventLoop::class);

        $fiber = new RequestFiber($this->app, $request, $handler, $params);
        $fiber->start();

        while (!$fiber->isCompleted()) {
            $loop->tick();
            // Stall: fiber still suspended but the loop has nothing left to
            // resume it. Surface that as an explicit error instead of
            // silently falling through and producing a misleading
            // "must return Response" downstream.
            if (!$fiber->isCompleted() && $loop->isIdle()) {
                throw new RuntimeException(
                    'Request fiber suspended with no pending work — nothing will resume it. '
                    . 'The handler likely awaited an event loop operation (timer/stream/deferred) '
                    . 'that was never scheduled.'
                );
            }
        }

        if ($fiber->getError()) {
            throw $fiber->getError();
        }

        $output = $fiber->getOutput();

        if (!$output instanceof Response) {
            throw new RuntimeException(
                'Controller must return a Response object. Got: ' . get_debug_type($output)
                . '. Use $this->json() for JSON or $this->view() for HTML.'
            );
        }

        return $output;
    }

    /**
     * Resolve handler to Component or callable.
     */
    private function resolveHandler(mixed $handler, Request $request): Component|callable
    {
        if (is_string($handler) && class_exists($handler) && is_subclass_of($handler, Component::class)) {
            return new $handler($this->app, $request);
        }

        if (is_array($handler)) {
            return $this->wrapControllerInLifecycle($handler);
        }

        if (is_callable($handler)) {
            return $handler;
        }

        throw new RuntimeException('Invalid route handler');
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
