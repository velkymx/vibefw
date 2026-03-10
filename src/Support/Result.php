<?php

declare(strict_types=1);

namespace Fw\Support;

use LogicException;
use Throwable;

/**
 * Result type for explicit error handling.
 *
 * Replaces exception-based control flow with explicit success/failure handling.
 * Inspired by Rust's Result<T, E> type.
 *
 * Usage:
 *     function divide(int $a, int $b): Result {
 *         if ($b === 0) {
 *             return Result::err('Division by zero');
 *         }
 *         return Result::ok($a / $b);
 *     }
 *
 *     $result = divide(10, 2);
 *     if ($result->isOk()) {
 *         echo $result->unwrap(); // 5
 *     }
 *
 *     // Or use map/flatMap for functional composition
 *     $doubled = divide(10, 2)->map(fn($x) => $x * 2); // Result::ok(10)
 *
 * @template T The success value type
 * @template E The error value type
 */
final readonly class Result
{
    /**
     * @param bool $success Whether this is a success result
     * @param T|null $value The success value (null if error)
     * @param E|null $error The error value (null if success)
     */
    private function __construct(
        private bool $success,
        private mixed $value,
        private mixed $error
    ) {}

    /**
     * Create a success result.
     *
     * @template U
     * @param U $value
     * @return Result<U, never>
     */
    public static function ok(mixed $value = null): self
    {
        return new self(true, $value, null);
    }

    /**
     * Create an error result.
     *
     * @template F
     * @param F $error
     * @return Result<never, F>
     */
    public static function err(mixed $error): self
    {
        return new self(false, null, $error);
    }

    /**
     * Create a Result from a callable that might throw.
     *
     * @template U
     * @param callable(): U $fn
     * @return Result<U, Throwable>
     */
    public static function try(callable $fn): self
    {
        try {
            return self::ok($fn());
        } catch (Throwable $e) {
            return self::err($e);
        }
    }

    /**
     * Create a Result from a nullable value.
     *
     * @template U
     * @param U|null $value
     * @param E $error Error to use if value is null
     * @return Result<U, E>
     */
    public static function fromNullable(mixed $value, mixed $error): self
    {
        return $value !== null ? self::ok($value) : self::err($error);
    }

    /**
     * Combine multiple Results, returning first error or all successes.
     *
     * @template U
     * @param array<Result<U, E>> $results
     * @return Result<array<U>, E>
     */
    public static function all(array $results): self
    {
        $values = [];

        foreach ($results as $result) {
            if ($result->isErr()) {
                return $result;
            }
            $values[] = $result->value;
        }

        return self::ok($values);
    }

    /**
     * Return first success Result, or last error if all fail.
     *
     * @template U
     * @param array<Result<U, E>> $results
     * @return Result<U, E>
     */
    public static function any(array $results): self
    {
        $lastErr = null;

        foreach ($results as $result) {
            if ($result->isOk()) {
                return $result;
            }
            $lastErr = $result;
        }

        return $lastErr ?? self::err('No results provided');
    }

    /**
     * Check if this is a success result.
     */
    public function isOk(): bool
    {
        return $this->success;
    }

    /**
     * Check if this is an error result.
     */
    public function isErr(): bool
    {
        return !$this->success;
    }

    /**
     * Get the success value, throwing if error.
     *
     * @return T
     * @throws LogicException If this is an error result
     */
    public function unwrap(): mixed
    {
        if (!$this->success) {
            $message = $this->error instanceof Throwable
                ? $this->error->getMessage()
                : (is_string($this->error) ? $this->error : 'Result is an error');

            throw new LogicException("Called unwrap() on error Result: {$message}");
        }

        return $this->value;
    }

    /**
     * Get the success value or a default.
     *
     * @template D
     * @param D $default
     * @return T|D
     */
    public function unwrapOr(mixed $default): mixed
    {
        return $this->success ? $this->value : $default;
    }

    /**
     * Get the success value or compute a default.
     *
     * @template D
     * @param callable(E): D $fn
     * @return T|D
     */
    public function unwrapOrElse(callable $fn): mixed
    {
        return $this->success ? $this->value : $fn($this->error);
    }

    /**
     * Get the error value, throwing if success.
     *
     * @return E
     * @throws LogicException If this is a success result
     */
    public function unwrapErr(): mixed
    {
        if ($this->success) {
            throw new LogicException('Called unwrapErr() on success Result');
        }

        return $this->error;
    }

    /**
     * Get the success value or null.
     *
     * @return T|null
     */
    public function getValue(): mixed
    {
        return $this->success ? $this->value : null;
    }

    /**
     * Get the error value or null.
     *
     * @return E|null
     */
    public function getError(): mixed
    {
        return $this->success ? null : $this->error;
    }

    /**
     * Transform the success value.
     *
     * @template U
     * @param callable(T): U $fn
     * @return Result<U, E>
     */
    public function map(callable $fn): self
    {
        if (!$this->success) {
            return $this;
        }

        return self::ok($fn($this->value));
    }

    /**
     * Transform the error value.
     *
     * @template F
     * @param callable(E): F $fn
     * @return Result<T, F>
     */
    public function mapErr(callable $fn): self
    {
        if ($this->success) {
            return $this;
        }

        return self::err($fn($this->error));
    }

    /**
     * Transform the success value with a Result-returning function.
     *
     * @template U
     * @param callable(T): Result<U, E> $fn
     * @return Result<U, E>
     */
    public function flatMap(callable $fn): self
    {
        if (!$this->success) {
            return $this;
        }

        return $fn($this->value);
    }

    /**
     * Alias for flatMap (common in other languages).
     *
     * @template U
     * @param callable(T): Result<U, E> $fn
     * @return Result<U, E>
     */
    public function andThen(callable $fn): self
    {
        return $this->flatMap($fn);
    }

    /**
     * Return this Result if success, otherwise return $other.
     *
     * @template F
     * @param Result<T, F> $other
     * @return Result<T, F>
     */
    public function orElse(self $other): self
    {
        return $this->success ? $this : $other;
    }

    /**
     * Return this Result if success, otherwise compute from error.
     *
     * @template U
     * @template F
     * @param callable(E): Result<U, F> $fn
     * @return Result<T|U, F>
     */
    public function orElseTry(callable $fn): self
    {
        return $this->success ? $this : $fn($this->error);
    }

    /**
     * Execute a callback if success, returning self for chaining.
     *
     * @param callable(T): void $fn
     * @return self
     */
    public function tap(callable $fn): self
    {
        if ($this->success) {
            $fn($this->value);
        }

        return $this;
    }

    /**
     * Execute a callback if error, returning self for chaining.
     *
     * @param callable(E): void $fn
     * @return self
     */
    public function tapErr(callable $fn): self
    {
        if (!$this->success) {
            $fn($this->error);
        }

        return $this;
    }

    /**
     * Check if success value satisfies a predicate.
     *
     * @param callable(T): bool $predicate
     */
    public function contains(callable $predicate): bool
    {
        return $this->success && $predicate($this->value);
    }

    /**
     * Match on success or error.
     *
     * @template U
     * @param callable(T): U $onOk
     * @param callable(E): U $onErr
     * @return U
     */
    public function match(callable $onOk, callable $onErr): mixed
    {
        return $this->success ? $onOk($this->value) : $onErr($this->error);
    }

    /**
     * Convert to an Option (Some if ok, None if err).
     *
     * @return Option<T>
     */
    public function toOption(): Option
    {
        return $this->success ? Option::some($this->value) : Option::none();
    }
}
