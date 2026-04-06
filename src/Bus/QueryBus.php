<?php

declare(strict_types=1);

namespace Fw\Bus;

use Fw\Support\Result;
use InvalidArgumentException;
use Throwable;

/**
 * Query Bus for dispatching queries to their handlers.
 *
 * The query bus handles read operations. Queries should not modify state.
 *
 * Usage:
 *     $bus = new QueryBus($container);
 *     $bus->register(GetUserById::class, GetUserByIdHandler::class);
 *
 *     $result = $bus->dispatch(new GetUserById($userId));
 *
 *     $user = $result->match(
 *         ok: fn($user) => $user,
 *         err: fn($e) => throw $e,
 *     );
 */
final class QueryBus
{
    /** @var array<class-string<Query>, class-string<Handler>|callable|Handler> */
    private array $handlers = [];

    /** @var array<callable(Query, callable): mixed> */
    private array $middleware = [];

    /**
     * @param callable(class-string): object $resolver Container resolver function
     */
    public function __construct(
        private readonly mixed $resolver = null
    ) {}

    /**
     * Register a handler for a query.
     *
     * @param class-string<Query> $queryClass
     * @param class-string<Handler>|callable|Handler $handler
     */
    public function register(string $queryClass, string|callable|Handler $handler): self
    {
        $this->handlers[$queryClass] = $handler;
        return $this;
    }

    /**
     * Add middleware to the bus.
     *
     * Middleware for queries can:
     * - Cache results
     * - Log queries
     * - Measure execution time
     *
     * @param callable(Query, callable): mixed $middleware
     */
    public function middleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Dispatch a query and return a Result.
     *
     * @template T
     * @param Query $query
     * @return Result<T, Throwable>
     */
    public function dispatch(Query $query): Result
    {
        return Result::try(fn () => $this->handle($query));
    }

    /**
     * Dispatch a query synchronously, throwing on error.
     *
     * @template T
     * @param Query $query
     * @return T
     * @throws Throwable
     */
    public function dispatchSync(Query $query): mixed
    {
        return $this->handle($query);
    }

    /**
     * Handle a query through middleware and handler.
     */
    private function handle(Query $query): mixed
    {
        $handler = $this->resolveHandler($query);

        // Build middleware chain
        $chain = fn ($q) => $handler->handle($q);

        foreach (array_reverse($this->middleware) as $middleware) {
            $chain = fn ($q) => $middleware($q, $chain);
        }

        return $chain($query);
    }

    /**
     * Resolve the handler for a query.
     */
    private function resolveHandler(Query $query): Handler
    {
        $queryClass = $query::class;

        if (!isset($this->handlers[$queryClass])) {
            // Try convention-based resolution: GetUser -> GetUserHandler
            $handlerClass = $queryClass . 'Handler';

            if (!class_exists($handlerClass)) {
                throw new InvalidArgumentException(
                    "No handler registered for query: {$queryClass}"
                );
            }

            $this->handlers[$queryClass] = $handlerClass;
        }

        $handler = $this->handlers[$queryClass];

        // Already a handler instance
        if ($handler instanceof Handler) {
            return $handler;
        }

        // Callable handler
        if (is_callable($handler) && !is_string($handler)) {
            return new class ($handler) implements Handler {
                public function __construct(private readonly mixed $callable) {}

                public function handle(Query $query): mixed
                {
                    return ($this->callable)($query);
                }
            };
        }

        // Class name - resolve from container or instantiate
        if (is_string($handler) && class_exists($handler)) {
            if ($this->resolver !== null) {
                $resolved = ($this->resolver)($handler);
            } else {
                $resolved = new $handler();
            }

            if (!$resolved instanceof Handler) {
                throw new InvalidArgumentException(
                    "Handler {$handler} must implement " . Handler::class
                );
            }

            return $resolved;
        }

        throw new InvalidArgumentException(
            "Invalid handler for query: {$queryClass}"
        );
    }
}
