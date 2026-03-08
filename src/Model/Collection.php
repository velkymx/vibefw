<?php

declare(strict_types=1);

namespace Fw\Model;

use ArrayAccess;
use ArrayIterator;
use Countable;
use Fw\Support\Option;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * A typed collection of models with functional methods.
 *
 * @template T
 * @implements ArrayAccess<int, T>
 * @implements IteratorAggregate<int, T>
 */
final class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param array<int, T> $items
     */
    public function __construct(
        private array $items = []
    ) {}

    // ========================================
    // STATIC CONSTRUCTORS
    // ========================================

    /**
     * Create collection from items.
     *
     * @template U
     * @param array<U> $items
     * @return Collection<U>
     */
    public static function make(array $items = []): self
    {
        return new self($items);
    }

    /**
     * Create empty collection.
     *
     * @return Collection<mixed>
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Create collection with a range.
     *
     * @return Collection<int>
     */
    public static function range(int $start, int $end, int $step = 1): self
    {
        return new self(range($start, $end, $step));
    }

    /**
     * Create collection by repeating a value.
     *
     * @template U
     * @param U $value
     * @return Collection<U>
     */
    public static function repeat(mixed $value, int $times): self
    {
        return new self(array_fill(0, $times, $value));
    }

    // ========================================
    // BASIC ACCESSORS
    // ========================================

    /**
     * Get all items as array.
     *
     * @return array<int, T>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Get the first item.
     *
     * @return Option<T>
     */
    public function first(): Option
    {
        if (empty($this->items)) {
            return Option::none();
        }

        // PHP 8.5 has array_first(), fallback for earlier versions
        if (function_exists('array_first')) {
            return Option::fromNullable(array_first($this->items));
        }

        $items = array_values($this->items);
        return Option::some($items[0]);
    }

    /**
     * Get the last item.
     *
     * @return Option<T>
     */
    public function last(): Option
    {
        if (empty($this->items)) {
            return Option::none();
        }

        // PHP 8.5 has array_last(), fallback for earlier versions
        if (function_exists('array_last')) {
            return Option::fromNullable(array_last($this->items));
        }

        $items = array_values($this->items);
        return Option::some($items[count($items) - 1]);
    }

    /**
     * Get item at index.
     *
     * @return Option<T>
     */
    public function get(int $index): Option
    {
        return Option::fromNullable($this->items[$index] ?? null);
    }

    /**
     * Check if collection is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Check if collection is not empty.
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Get the number of items.
     */
    public function count(): int
    {
        return count($this->items);
    }

    // ========================================
    // FUNCTIONAL METHODS
    // ========================================

    /**
     * Map over each item.
     *
     * @template U
     * @param callable(T, int): U $callback
     * @return Collection<U>
     */
    public function map(callable $callback): self
    {
        return new self(array_map($callback, $this->items, array_keys($this->items)));
    }

    /**
     * Filter items by callback.
     *
     * @param callable(T, int): bool $callback
     * @return Collection<T>
     */
    public function filter(callable $callback): self
    {
        return new self(array_values(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH)));
    }

    /**
     * Reject items by callback.
     *
     * @param callable(T, int): bool $callback
     * @return Collection<T>
     */
    public function reject(callable $callback): self
    {
        return $this->filter(fn ($item, $key) => !$callback($item, $key));
    }

    /**
     * Reduce collection to single value.
     *
     * @template U
     * @param callable(U, T): U $callback
     * @param U $initial
     * @return U
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /**
     * Execute callback for each item.
     *
     * @param callable(T, int): void $callback
     */
    public function each(callable $callback): self
    {
        foreach ($this->items as $key => $item) {
            $callback($item, $key);
        }

        return $this;
    }

    /**
     * Check if any item matches callback.
     *
     * @param callable(T): bool $callback
     */
    public function any(callable $callback): bool
    {
        foreach ($this->items as $item) {
            if ($callback($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if all items match callback.
     *
     * @param callable(T): bool $callback
     */
    public function every(callable $callback): bool
    {
        foreach ($this->items as $item) {
            if (!$callback($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Find first item matching callback.
     *
     * @param callable(T): bool $callback
     * @return Option<T>
     */
    public function find(callable $callback): Option
    {
        foreach ($this->items as $item) {
            if ($callback($item)) {
                return Option::some($item);
            }
        }
        return Option::none();
    }

    /**
     * Find last item matching callback.
     *
     * @param callable(T): bool $callback
     * @return Option<T>
     */
    public function findLast(callable $callback): Option
    {
        $result = null;
        foreach ($this->items as $item) {
            if ($callback($item)) {
                $result = $item;
            }
        }
        return Option::fromNullable($result);
    }

    /**
     * Pluck a single attribute from each item.
     *
     * @return array<mixed>
     */
    public function pluck(string $key, ?string $indexBy = null): array
    {
        $result = [];

        foreach ($this->items as $item) {
            $value = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);

            if ($indexBy !== null) {
                $index = is_array($item) ? ($item[$indexBy] ?? null) : ($item->$indexBy ?? null);
                $result[$index] = $value;
            } else {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * Get unique items.
     *
     * @param string|callable|null $key
     * @return Collection<T>
     */
    public function unique(string|callable|null $key = null): self
    {
        if ($key === null) {
            return new self(array_values(array_unique($this->items, SORT_REGULAR)));
        }

        $seen = [];
        $result = [];

        foreach ($this->items as $item) {
            $value = is_callable($key) ? $key($item) : (is_array($item) ? $item[$key] : $item->$key);

            if (!in_array($value, $seen, true)) {
                $seen[] = $value;
                $result[] = $item;
            }
        }

        return new self($result);
    }

    /**
     * Sort the collection.
     *
     * @param callable(T, T): int|string|null $callback
     * @return Collection<T>
     */
    public function sort(callable|string|null $callback = null): self
    {
        $items = $this->items;

        if ($callback === null) {
            sort($items);
        } elseif (is_string($callback)) {
            usort($items, fn ($a, $b) => ($a->$callback ?? 0) <=> ($b->$callback ?? 0));
        } else {
            usort($items, $callback);
        }

        return new self($items);
    }

    /**
     * Sort by a key in descending order.
     *
     * @return Collection<T>
     */
    public function sortByDesc(string $key): self
    {
        $items = $this->items;
        usort($items, fn ($a, $b) => ($b->$key ?? 0) <=> ($a->$key ?? 0));
        return new self($items);
    }

    /**
     * Reverse the collection.
     *
     * @return Collection<T>
     */
    public function reverse(): self
    {
        return new self(array_reverse($this->items));
    }

    /**
     * Take first n items.
     *
     * @return Collection<T>
     */
    public function take(int $count): self
    {
        return new self(array_slice($this->items, 0, $count));
    }

    /**
     * Skip first n items.
     *
     * @return Collection<T>
     */
    public function skip(int $count): self
    {
        return new self(array_slice($this->items, $count));
    }

    /**
     * Chunk the collection into groups.
     *
     * @return Collection<Collection<T>>
     */
    public function chunk(int $size): self
    {
        return new self(array_map(
            fn ($chunk) => new self($chunk),
            array_chunk($this->items, $size)
        ));
    }

    /**
     * Flatten nested collections.
     *
     * @return Collection<mixed>
     */
    public function flatten(int $depth = INF): self
    {
        $result = [];

        foreach ($this->items as $item) {
            if ($item instanceof self) {
                $result = array_merge(
                    $result,
                    $depth > 1 ? $item->flatten($depth - 1)->all() : $item->all()
                );
            } elseif (is_array($item)) {
                $result = array_merge(
                    $result,
                    $depth > 1 ? new self($item)->flatten($depth - 1)->all() : $item
                );
            } else {
                $result[] = $item;
            }
        }

        return new self($result);
    }

    /**
     * Group items by a key.
     *
     * @return Collection<Collection<T>>
     */
    public function groupBy(string|callable $key): self
    {
        $groups = [];

        foreach ($this->items as $item) {
            $groupKey = is_callable($key) ? $key($item) : (is_array($item) ? $item[$key] : $item->$key);
            $groups[$groupKey][] = $item;
        }

        return new self(array_map(fn ($group) => new self($group), $groups));
    }

    /**
     * Key the collection by a field.
     *
     * @return Collection<T>
     */
    public function keyBy(string|callable $key): self
    {
        $result = [];

        foreach ($this->items as $item) {
            $keyValue = is_callable($key) ? $key($item) : (is_array($item) ? $item[$key] : $item->$key);
            $result[$keyValue] = $item;
        }

        return new self($result);
    }

    /**
     * Merge with another collection.
     *
     * @param Collection<T>|array<T> $items
     * @return Collection<T>
     */
    public function merge(self|array $items): self
    {
        $items = $items instanceof self ? $items->all() : $items;
        return new self(array_merge($this->items, $items));
    }

    /**
     * Combine collection values with keys.
     *
     * @param array<mixed> $keys
     * @return Collection<T>
     */
    public function combine(array $keys): self
    {
        return new self(array_combine($keys, $this->items));
    }

    /**
     * Check if collection contains a value.
     *
     * @param T|callable(T): bool $value
     */
    public function contains(mixed $value): bool
    {
        if (is_callable($value)) {
            return $this->any($value);
        }

        return in_array($value, $this->items, true);
    }

    // ========================================
    // AGGREGATE METHODS
    // ========================================

    /**
     * Sum values.
     */
    public function sum(string|callable|null $key = null): float|int
    {
        if ($key === null) {
            return array_sum($this->items);
        }

        return array_sum($this->pluck(is_string($key) ? $key : 'value'));
    }

    /**
     * Average values.
     */
    public function avg(string|callable|null $key = null): float
    {
        $count = $this->count();

        if ($count === 0) {
            return 0;
        }

        return $this->sum($key) / $count;
    }

    /**
     * Min value.
     */
    public function min(string|callable|null $key = null): mixed
    {
        if ($key === null) {
            return min($this->items);
        }

        $values = $this->pluck(is_string($key) ? $key : 'value');
        return empty($values) ? null : min($values);
    }

    /**
     * Max value.
     */
    public function max(string|callable|null $key = null): mixed
    {
        if ($key === null) {
            return max($this->items);
        }

        $values = $this->pluck(is_string($key) ? $key : 'value');
        return empty($values) ? null : max($values);
    }

    // ========================================
    // MUTATION METHODS
    // ========================================

    /**
     * Push item to collection.
     *
     * @param T $item
     * @return Collection<T>
     */
    public function push(mixed $item): self
    {
        $items = $this->items;
        $items[] = $item;
        return new self($items);
    }

    /**
     * Pop item from collection.
     *
     * @return array{Collection<T>, Option<T>}
     */
    public function pop(): array
    {
        $items = $this->items;
        $popped = array_pop($items);
        return [new self($items), Option::fromNullable($popped)];
    }

    /**
     * Put item at key.
     *
     * @param T $value
     * @return Collection<T>
     */
    public function put(int|string $key, mixed $value): self
    {
        $items = $this->items;
        $items[$key] = $value;
        return new self($items);
    }

    /**
     * Forget an item by key.
     *
     * @return Collection<T>
     */
    public function forget(int|string $key): self
    {
        $items = $this->items;
        unset($items[$key]);
        return new self(array_values($items));
    }

    // ========================================
    // CONVERSION METHODS
    // ========================================

    /**
     * Convert to array, calling toArray() on models.
     *
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return array_map(function ($item) {
            if ($item instanceof Model) {
                return $item->toArray();
            }

            if ($item instanceof self) {
                return $item->toArray();
            }

            return $item;
        }, $this->items);
    }

    /**
     * Convert to JSON.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    // ========================================
    // INTERFACE IMPLEMENTATIONS
    // ========================================

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
