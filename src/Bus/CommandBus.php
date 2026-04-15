<?php

declare(strict_types=1);

namespace Fw\Bus;

use Fw\Support\Result;
use InvalidArgumentException;
use Throwable;

/**
 * Command Bus for dispatching commands to their handlers.
 *
 * The command bus is the single entry point for executing commands.
 * It resolves the appropriate handler and invokes it.
 *
 * Usage:
 *     $bus = new CommandBus($container);
 *     $bus->register(CreateUser::class, CreateUserHandler::class);
 *
 *     // Dispatch returns Result<T, Throwable>
 *     $result = $bus->dispatch(new CreateUser('john@example.com', 'John'));
 *
 *     $user = $result->match(
 *         ok: fn($user) => $user,
 *         err: fn($e) => throw $e,
 *     );
 */
final class CommandBus
{
    /** @var array<class-string<Command>, class-string<Handler>|callable|Handler> */
    private array $handlers = [];

    /** @var array<callable(Command, callable): mixed> */
    private array $middleware = [];

    /**
     * @param callable(class-string): object $resolver Container resolver function
     */
    public function __construct(
        private readonly mixed $resolver = null
    ) {}

    /**
     * Register a handler for a command.
     *
     * @param class-string<Command> $commandClass
     * @param class-string<Handler>|callable|Handler $handler
     */
    public function register(string $commandClass, string|callable|Handler $handler): self
    {
        $this->handlers[$commandClass] = $handler;
        return $this;
    }

    /**
     * Add middleware to the bus.
     *
     * Middleware wraps command execution and can:
     * - Log commands
     * - Handle transactions
     * - Validate commands
     * - Measure execution time
     *
     * @param callable(Command, callable): mixed $middleware
     */
    public function middleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Dispatch a command and return a Result.
     *
     * @template T
     * @param Command $command
     * @return Result<T, Throwable>
     */
    public function dispatch(Command $command): Result
    {
        return Result::try(fn () => $this->handle($command));
    }

    /**
     * Dispatch a command, throwing on error.
     *
     * @template T
     * @param Command $command
     * @return T
     * @throws Throwable
     */
    public function dispatchSync(Command $command): mixed
    {
        return $this->handle($command);
    }

    /**
     * Handle a command through middleware and handler.
     */
    private function handle(Command $command): mixed
    {
        $handler = $this->resolveHandler($command);

        // Build middleware chain
        $chain = fn ($cmd) => $handler->handle($cmd);

        foreach (array_reverse($this->middleware) as $middleware) {
            $chain = fn ($cmd) => $middleware($cmd, $chain);
        }

        return $chain($command);
    }

    /**
     * Resolve the handler for a command.
     */
    private function resolveHandler(Command $command): Handler
    {
        $commandClass = $command::class;

        if (!isset($this->handlers[$commandClass])) {
            // Try convention-based resolution: CreateUser -> CreateUserHandler
            $handlerClass = $commandClass . 'Handler';

            if (!class_exists($handlerClass)) {
                throw new InvalidArgumentException(
                    "No handler registered for command: {$commandClass}. " .
                    "Register one via \$bus->register() or create a class named: {$handlerClass}"
                );
            }

            $this->handlers[$commandClass] = $handlerClass;
        }

        $handler = $this->handlers[$commandClass];

        // Already a handler instance
        if ($handler instanceof Handler) {
            return $handler;
        }

        // Callable handler
        if (is_callable($handler) && !is_string($handler)) {
            return new class ($handler) implements Handler {
                public function __construct(private readonly mixed $callable) {}

                public function handle(Command $command): mixed
                {
                    return ($this->callable)($command);
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
            "Invalid handler for command: {$commandClass}"
        );
    }
}
