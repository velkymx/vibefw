<?php

declare(strict_types=1);

namespace Fw\Core;

use Fw\Support\Option;

/**
 * Request-scoped data sharing between services.
 *
 * RequestContext provides a way to share data across different parts
 * of the application during a single request lifecycle. Unlike session
 * or global state, context is cleared after each request.
 *
 * Common use cases:
 * - Storing authenticated user without passing through every layer
 * - Request correlation IDs for logging
 * - Locale/timezone settings for the current request
 * - Performance metrics and timing
 *
 * Example:
 *     // In auth middleware
 *     RequestContext::current()?->set('user', $authenticatedUser);
 *
 *     // In a service
 *     $user = RequestContext::current()?->get('user')->unwrapOr(null);
 *
 *     // Or using typed accessor
 *     $user = RequestContext::current()?->user();
 */
final class RequestContext
{
    /** @var \WeakMap<object, RequestContext> */
    private static ?\WeakMap $instances = null;

    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    private Request $request;

    private function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Create a new context for the current request.
     *
     * Should be called once at the start of request processing.
     */
    public static function create(Request $request): self
    {
        $instance = new self($request);
        self::getInstances()[self::getCurrentKey()] = $instance;
        return $instance;
    }

    /**
     * Get the current request context.
     *
     * Returns null if no context has been created for this request.
     */
    public static function current(): ?self
    {
        return self::getInstances()[self::getCurrentKey()] ?? null;
    }

    /**
     * Clear the current context.
     *
     * Should be called after the response is sent.
     */
    public static function clear(): void
    {
        unset(self::getInstances()[self::getCurrentKey()]);
    }

    /**
     * Internal helper to get the instances WeakMap.
     *
     * @return \WeakMap<object, RequestContext>
     */
    private static function getInstances(): \WeakMap
    {
        return self::$instances ??= new \WeakMap();
    }

    /**
     * Get the key for the current execution context (Fiber or a fallback object).
     */
    private static function getCurrentKey(): object
    {
        return \Fiber::getCurrent() ?? self::getGlobalKey();
    }

    /**
     * Static object used as a key for the global (non-fiber) context.
     */
    private static ?object $globalKey = null;

    private static function getGlobalKey(): object
    {
        return self::$globalKey ??= new \stdClass();
    }

    /**
     * Get the request associated with this context.
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Set a value in the context.
     */
    public function set(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * Get a value from the context.
     *
     * @return Option<mixed>
     */
    public function get(string $key): Option
    {
        if (!array_key_exists($key, $this->data)) {
            return Option::none();
        }

        return Option::some($this->data[$key]);
    }

    /**
     * Check if a key exists in the context.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Remove a value from the context.
     */
    public function forget(string $key): self
    {
        unset($this->data[$key]);
        return $this;
    }

    /**
     * Get all context data.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Merge multiple values into the context.
     *
     * @param array<string, mixed> $data
     */
    public function merge(array $data): self
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    // ========================================
    // TYPED ACCESSORS
    // ========================================

    /**
     * Get the authenticated user from context.
     *
     * @return Option<mixed>
     */
    public function user(): Option
    {
        return $this->get('user');
    }

    /**
     * Set the authenticated user.
     */
    public function setUser(mixed $user): self
    {
        return $this->set('user', $user);
    }

    /**
     * Get the request correlation ID.
     */
    public function correlationId(): string
    {
        return $this->get('correlation_id')->unwrapOr(
            $this->generateCorrelationId()
        );
    }

    /**
     * Generate and store a correlation ID.
     */
    private function generateCorrelationId(): string
    {
        $id = bin2hex(random_bytes(16));
        $this->set('correlation_id', $id);
        return $id;
    }

    /**
     * Get the current locale.
     */
    public function locale(): string
    {
        return $this->get('locale')->unwrapOr('en');
    }

    /**
     * Set the current locale.
     */
    public function setLocale(string $locale): self
    {
        return $this->set('locale', $locale);
    }

    /**
     * Get the current timezone.
     */
    public function timezone(): string
    {
        return $this->get('timezone')->unwrapOr('UTC');
    }

    /**
     * Set the current timezone.
     */
    public function setTimezone(string $timezone): self
    {
        return $this->set('timezone', $timezone);
    }

    /**
     * Get the current route match.
     *
     * @return Option<RouteMatch>
     */
    public function routeMatch(): Option
    {
        return $this->get('_route_match');
    }

    /**
     * Set the current route match.
     */
    public function setRouteMatch(RouteMatch $match): self
    {
        return $this->set('_route_match', $match);
    }

    /**
     * Get a route parameter by name.
     *
     * @return Option<mixed>
     */
    public function routeParam(string $name): Option
    {
        $match = $this->routeMatch();

        if ($match->isNone()) {
            return Option::none();
        }

        $params = $match->unwrap()->params;

        if (!array_key_exists($name, $params)) {
            return Option::none();
        }

        return Option::some($params[$name]);
    }

    /**
     * Get all route parameters.
     *
     * @return array<string, mixed>
     */
    public function routeParams(): array
    {
        return $this->routeMatch()
            ->map(fn(RouteMatch $m) => $m->params)
            ->unwrapOr([]);
    }
}
