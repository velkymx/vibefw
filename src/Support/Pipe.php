<?php

declare(strict_types=1);

namespace Fw\Support;

use Closure;
use RuntimeException;
use Throwable;

/**
 * Pipe-friendly helper functions for PHP 8.5's pipe operator (|>).
 *
 * These functions return closures that work seamlessly with the pipe operator,
 * enabling functional composition and point-free style programming.
 *
 * Example:
 *   $result = $data
 *       |> Pipe::validate(['name' => 'required'])
 *       |> Pipe::map(fn($d) => strtoupper($d['name']))
 *       |> Pipe::tap(fn($name) => log("Processing: $name"));
 */
final class Pipe
{
    /**
     * Create a mapping function for the pipe.
     *
     * @template T
     * @template U
     * @param callable(T): U $fn
     * @return Closure(T): U
     */
    public static function map(callable $fn): Closure
    {
        return fn (mixed $value) => $fn($value);
    }

    /**
     * Create a filter function for the pipe.
     * Returns the value if predicate is true, null otherwise.
     *
     * @template T
     * @param callable(T): bool $predicate
     * @return Closure(T): ?T
     */
    public static function filter(callable $predicate): Closure
    {
        return fn (mixed $value) => $predicate($value) ? $value : null;
    }

    /**
     * Execute a side effect without changing the value.
     *
     * @template T
     * @param callable(T): void $fn
     * @return Closure(T): T
     */
    public static function tap(callable $fn): Closure
    {
        return function (mixed $value) use ($fn) {
            $fn($value);
            return $value;
        };
    }

    /**
     * Provide a default if the value is null.
     *
     * @template T
     * @param T $default
     * @return Closure(?T): T
     */
    public static function orElse(mixed $default): Closure
    {
        return fn (mixed $value) => $value ?? $default;
    }

    /**
     * Apply a function if value is not null.
     *
     * @template T
     * @template U
     * @param callable(T): U $fn
     * @return Closure(?T): ?U
     */
    public static function mapNullable(callable $fn): Closure
    {
        return fn (mixed $value) => $value !== null ? $fn($value) : null;
    }

    /**
     * Wrap value in Option.
     *
     * @template T
     * @return Closure(T): Option<T>
     */
    public static function toOption(): Closure
    {
        return fn (mixed $value) => Option::fromNullable($value);
    }

    /**
     * Wrap value in successful Result.
     *
     * @template T
     * @return Closure(T): Result<T, never>
     */
    public static function toOk(): Closure
    {
        return fn (mixed $value) => Result::ok($value);
    }

    /**
     * Convert array to Collection.
     *
     * @template T
     * @return Closure(array<T>): \Fw\Model\Collection<T>
     */
    public static function collect(): Closure
    {
        return fn (array $items) => new \Fw\Model\Collection($items);
    }

    /**
     * Get a property or array key from the value.
     *
     * @return Closure(object|array): mixed
     */
    public static function get(string $key): Closure
    {
        return function (mixed $value) use ($key) {
            if (is_array($value)) {
                return $value[$key] ?? null;
            }
            if (is_object($value)) {
                return $value->$key ?? null;
            }
            return null;
        };
    }

    /**
     * Pluck a key from each item in an array.
     *
     * @return Closure(array): array
     */
    public static function pluck(string $key): Closure
    {
        return fn (array $items) => array_map(
            fn ($item) => is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null),
            $items
        );
    }

    /**
     * Filter an array by callback.
     *
     * @param callable(mixed): bool $predicate
     * @return Closure(array): array
     */
    public static function filterArray(callable $predicate): Closure
    {
        return fn (array $items) => array_values(array_filter($items, $predicate));
    }

    /**
     * Sort an array.
     *
     * @param callable(mixed, mixed): int|null $comparator
     * @return Closure(array): array
     */
    public static function sort(?callable $comparator = null): Closure
    {
        return function (array $items) use ($comparator) {
            if ($comparator === null) {
                sort($items);
            } else {
                usort($items, $comparator);
            }
            return $items;
        };
    }

    /**
     * Take first N items from array.
     *
     * @return Closure(array): array
     */
    public static function take(int $count): Closure
    {
        return fn (array $items) => array_slice($items, 0, $count);
    }

    /**
     * Get first item from array.
     *
     * @template T
     * @param callable(T): bool|null $predicate Optional filter
     * @return Closure(array<T>): ?T
     */
    public static function first(?callable $predicate = null): Closure
    {
        return function (array $items) use ($predicate) {
            if ($predicate === null) {
                return empty($items) ? null : reset($items);
            }
            foreach ($items as $item) {
                if ($predicate($item)) {
                    return $item;
                }
            }
            return null;
        };
    }

    /**
     * Get last item from array.
     *
     * @template T
     * @param callable(T): bool|null $predicate Optional filter
     * @return Closure(array<T>): ?T
     */
    public static function last(?callable $predicate = null): Closure
    {
        return function (array $items) use ($predicate) {
            if ($predicate === null) {
                return empty($items) ? null : end($items);
            }
            $result = null;
            foreach ($items as $item) {
                if ($predicate($item)) {
                    $result = $item;
                }
            }
            return $result;
        };
    }

    /**
     * Apply multiple transformations in sequence.
     *
     * @param callable ...$fns
     * @return Closure(mixed): mixed
     */
    public static function compose(callable ...$fns): Closure
    {
        return function (mixed $value) use ($fns) {
            foreach ($fns as $fn) {
                $value = $fn($value);
            }
            return $value;
        };
    }

    /**
     * Debug the current value in the pipe.
     *
     * @param string|null $label Optional label for output
     * @return Closure(mixed): mixed
     */
    public static function debug(?string $label = null): Closure
    {
        return function (mixed $value) use ($label) {
            if ($label) {
                echo "[{$label}] ";
            }
            var_dump($value); // @nosecurity
            return $value;
        };
    }

    /**
     * Conditional transformation.
     *
     * @template T
     * @template U
     * @param callable(T): bool $predicate
     * @param callable(T): U $then
     * @param callable(T): U|null $else
     * @return Closure(T): T|U
     */
    public static function when(callable $predicate, callable $then, ?callable $else = null): Closure
    {
        return function (mixed $value) use ($predicate, $then, $else) {
            if ($predicate($value)) {
                return $then($value);
            }
            return $else ? $else($value) : $value;
        };
    }

    /**
     * Throw an exception if condition is met.
     *
     * @param callable(mixed): bool $predicate
     * @param string|Throwable $exception
     * @return Closure(mixed): mixed
     */
    public static function throwIf(callable $predicate, string|Throwable $exception): Closure
    {
        return function (mixed $value) use ($predicate, $exception) {
            if ($predicate($value)) {
                if (is_string($exception)) {
                    throw new RuntimeException($exception);
                }
                throw $exception;
            }
            return $value;
        };
    }
}
