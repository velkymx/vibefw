<?php

declare(strict_types=1);

namespace Fw\Support;

use LogicException;

/**
 * Option type for explicit null handling.
 *
 * Replaces nullable types with explicit Some/None semantics.
 * Inspired by Rust's Option<T> type.
 *
 * Usage:
 *     function findUser(int $id): Option {
 *         $user = $db->find($id);
 *         return $user ? Option::some($user) : Option::none();
 *     }
 *
 *     $name = findUser(1)
 *         ->map(fn($u) => $u->name)
 *         ->unwrapOr('Guest');
 *
 * @template T The value type
 */
final readonly class Option
{
    /**
     * @param bool $some Whether this contains a value
     * @param T|null $value The contained value
     */
    private function __construct(
        private bool $some,
        private mixed $value
    ) {}

    /**
     * Create an Option containing a value.
     *
     * @template U
     * @param U $value
     * @return Option<U>
     */
    public static function some(mixed $value): self
    {
        return new self(true, $value);
    }

    /**
     * Create an empty Option.
     *
     * @return Option<never>
     */
    public static function none(): self
    {
        return new self(false, null);
    }

    /**
     * Create an Option from a nullable value.
     *
     * @template U
     * @param U|null $value
     * @return Option<U>
     */
    public static function fromNullable(mixed $value): self
    {
        return $value !== null ? self::some($value) : self::none();
    }

    /**
     * Check if this contains a value.
     */
    public function isSome(): bool
    {
        return $this->some;
    }

    /**
     * Check if this is empty.
     */
    public function isNone(): bool
    {
        return !$this->some;
    }

    /**
     * Get the value, throwing if empty.
     *
     * @return T
     * @throws LogicException If this is None
     */
    public function unwrap(): mixed
    {
        if (!$this->some) {
            throw new LogicException('Called unwrap() on None Option');
        }

        return $this->value;
    }

    /**
     * Get the value or a default.
     *
     * @template D
     * @param D $default
     * @return T|D
     */
    public function unwrapOr(mixed $default): mixed
    {
        return $this->some ? $this->value : $default;
    }

    /**
     * Get the value or compute a default.
     *
     * @template D
     * @param callable(): D $fn
     * @return T|D
     */
    public function unwrapOrElse(callable $fn): mixed
    {
        return $this->some ? $this->value : $fn();
    }

    /**
     * Get the value or null.
     *
     * @return T|null
     */
    public function get(): mixed
    {
        return $this->value;
    }

    /**
     * Transform the value if present.
     *
     * @template U
     * @param callable(T): U $fn
     * @return Option<U>
     */
    #[\NoDiscard]
    public function map(callable $fn): self
    {
        if (!$this->some) {
            return $this;
        }

        return self::some($fn($this->value));
    }

    /**
     * Transform with an Option-returning function.
     *
     * @template U
     * @param callable(T): Option<U> $fn
     * @return Option<U>
     */
    public function flatMap(callable $fn): self
    {
        if (!$this->some) {
            return $this;
        }

        return $fn($this->value);
    }

    /**
     * Alias for flatMap.
     *
     * @template U
     * @param callable(T): Option<U> $fn
     * @return Option<U>
     */
    public function andThen(callable $fn): self
    {
        return $this->flatMap($fn);
    }

    /**
     * Return this if Some, otherwise return $other.
     *
     * @param Option<T> $other
     * @return Option<T>
     */
    public function orElse(self $other): self
    {
        return $this->some ? $this : $other;
    }

    /**
     * Return this if Some, otherwise compute from callback.
     *
     * @param callable(): Option<T> $fn
     * @return Option<T>
     */
    public function orElseTry(callable $fn): self
    {
        return $this->some ? $this : $fn();
    }

    /**
     * Filter the value by a predicate.
     *
     * @param callable(T): bool $predicate
     * @return Option<T>
     */
    #[\NoDiscard]
    public function filter(callable $predicate): self
    {
        if (!$this->some) {
            return $this;
        }

        return $predicate($this->value) ? $this : self::none();
    }

    /**
     * Execute a callback if Some, returning self for chaining.
     *
     * @param callable(T): void $fn
     * @return self
     */
    public function tap(callable $fn): self
    {
        if ($this->some) {
            $fn($this->value);
        }

        return $this;
    }

    /**
     * Check if value satisfies a predicate.
     *
     * @param callable(T): bool $predicate
     */
    public function contains(callable $predicate): bool
    {
        return $this->some && $predicate($this->value);
    }

    /**
     * Match on Some or None.
     *
     * @template U
     * @param callable(T): U $onSome
     * @param callable(): U $onNone
     * @return U
     */
    public function match(callable $onSome, callable $onNone): mixed
    {
        return $this->some ? $onSome($this->value) : $onNone();
    }

    /**
     * Convert to a Result.
     *
     * @template E
     * @param E $error Error to use if None
     * @return Result<T, E>
     */
    public function toResult(mixed $error): Result
    {
        return $this->some ? Result::ok($this->value) : Result::err($error);
    }

    /**
     * Zip with another Option.
     *
     * @template U
     * @param Option<U> $other
     * @return Option<array{T, U}>
     */
    public function zip(self $other): self
    {
        if (!$this->some || !$other->some) {
            return self::none();
        }

        return self::some([$this->value, $other->value]);
    }
}
