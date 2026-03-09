<?php

declare(strict_types=1);

namespace Fw\Support;

use ArrayAccess;
use InvalidArgumentException;

/**
 * Array helper utilities.
 *
 * Provides fluent, expressive methods for working with arrays,
 * including dot notation access, plucking, filtering, and more.
 */
final class Arr
{
    /**
     * Get an item from an array using "dot" notation.
     *
     * @param array<mixed>|ArrayAccess<mixed, mixed> $array
     */
    public static function get(array|ArrayAccess $array, string|int|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $array;
        }

        if (self::exists($array, $key)) {
            return $array[$key];
        }

        if (!is_string($key) || !str_contains($key, '.')) {
            return $default;
        }

        foreach (explode('.', $key) as $segment) {
            if (self::accessible($array) && self::exists($array, $segment)) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }

        return $array;
    }

    /**
     * Set an array item to a given value using "dot" notation.
     *
     * @param array<mixed> $array
     * @return array<mixed>
     */
    public static function set(array &$array, string|int|null $key, mixed $value): array
    {
        if ($key === null) {
            return $array = $value;
        }

        $keys = is_string($key) ? explode('.', $key) : [$key];

        foreach ($keys as $i => $segment) {
            if (count($keys) === 1) {
                break;
            }

            unset($keys[$i]);

            if (!isset($array[$segment]) || !is_array($array[$segment])) {
                $array[$segment] = [];
            }

            $array = &$array[$segment];
        }

        $array[array_shift($keys)] = $value;

        return $array;
    }

    /**
     * Check if an item exists in an array using "dot" notation.
     *
     * @param array<mixed>|ArrayAccess<mixed, mixed> $array
     */
    public static function has(array|ArrayAccess $array, string|array $keys): bool
    {
        $keys = (array) $keys;

        if ($keys === [] || $array === []) {
            return false;
        }

        foreach ($keys as $key) {
            $subArray = $array;

            if (self::exists($array, $key)) {
                continue;
            }

            foreach (explode('.', $key) as $segment) {
                if (self::accessible($subArray) && self::exists($subArray, $segment)) {
                    $subArray = $subArray[$segment];
                } else {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Remove one or many array items from a given array using "dot" notation.
     *
     * @param array<mixed> $array
     */
    public static function forget(array &$array, string|array $keys): void
    {
        $original = &$array;
        $keys = (array) $keys;

        if ($keys === []) {
            return;
        }

        foreach ($keys as $key) {
            if (self::exists($array, $key)) {
                unset($array[$key]);
                continue;
            }

            $parts = explode('.', $key);
            $array = &$original;

            while (count($parts) > 1) {
                $part = array_shift($parts);

                if (isset($array[$part]) && self::accessible($array[$part])) {
                    $array = &$array[$part];
                } else {
                    continue 2;
                }
            }

            unset($array[array_shift($parts)]);
        }
    }

    /**
     * Pluck an array of values from an array.
     *
     * @param iterable<mixed> $array
     * @return array<mixed>
     */
    public static function pluck(iterable $array, string|array|int|null $value, string|array|null $key = null): array
    {
        $results = [];

        [$value, $key] = self::explodePluckParameters($value, $key);

        foreach ($array as $item) {
            $itemValue = self::dataGet($item, $value);

            if ($key === null) {
                $results[] = $itemValue;
            } else {
                $itemKey = self::dataGet($item, $key);
                if (is_object($itemKey) && method_exists($itemKey, '__toString')) {
                    $itemKey = (string) $itemKey;
                }
                $results[$itemKey] = $itemValue;
            }
        }

        return $results;
    }

    /**
     * Get a subset of the items from the given array.
     *
     * @param array<mixed> $array
     * @param array<string|int> $keys
     * @return array<mixed>
     */
    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /**
     * Get all of the given array except for specified keys.
     *
     * @param array<mixed> $array
     * @param array<string|int> $keys
     * @return array<mixed>
     */
    public static function except(array $array, array $keys): array
    {
        self::forget($array, $keys);
        return $array;
    }

    /**
     * Return the first element in an array passing a given truth test.
     *
     * @param iterable<mixed> $array
     * @param callable|null $callback fn(mixed $value, mixed $key): bool
     */
    public static function first(iterable $array, ?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            if (empty($array)) {
                return $default;
            }

            foreach ($array as $item) {
                return $item;
            }

            return $default;
        }

        // Use PHP 8.4's array_find if available and array is actual array
        if (is_array($array) && function_exists('array_find')) {
            $result = array_find($array, $callback);
            return $result ?? $default;
        }

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Return the last element in an array passing a given truth test.
     *
     * @param array<mixed> $array
     * @param callable|null $callback fn(mixed $value, mixed $key): bool
     */
    public static function last(array $array, ?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return empty($array) ? $default : end($array);
        }

        return self::first(array_reverse($array, true), $callback, $default);
    }

    /**
     * Flatten a multi-dimensional array into a single level.
     *
     * @param iterable<mixed> $array
     * @return array<mixed>
     */
    public static function flatten(iterable $array, int $depth = PHP_INT_MAX): array
    {
        $result = [];

        foreach ($array as $item) {
            if (!is_array($item)) {
                $result[] = $item;
            } else {
                $values = $depth === 1
                    ? array_values($item)
                    : self::flatten($item, $depth - 1);

                foreach ($values as $value) {
                    $result[] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Flatten a multi-dimensional associative array with dots.
     *
     * @param iterable<mixed> $array
     * @return array<string, mixed>
     */
    public static function dot(iterable $array, string $prepend = ''): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            if (is_array($value) && !empty($value)) {
                $results = array_merge($results, self::dot($value, $prepend . $key . '.'));
            } else {
                $results[$prepend . $key] = $value;
            }
        }

        return $results;
    }

    /**
     * Convert a flatten "dot" notation array into an expanded array.
     *
     * @param iterable<mixed> $array
     * @return array<mixed>
     */
    public static function undot(iterable $array): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            self::set($results, $key, $value);
        }

        return $results;
    }

    /**
     * If the given value is not an array, wrap it in one.
     *
     * @return array<mixed>
     */
    public static function wrap(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }

    /**
     * Filter the array using the given callback.
     *
     * @param array<mixed> $array
     * @param callable $callback fn(mixed $value, mixed $key): bool
     * @return array<mixed>
     */
    public static function where(array $array, callable $callback): array
    {
        return array_filter($array, $callback, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Filter items where the value is not null.
     *
     * @param array<mixed> $array
     * @return array<mixed>
     */
    public static function whereNotNull(array $array): array
    {
        return self::where($array, fn ($value) => $value !== null);
    }

    /**
     * Group an array by a field or using a callback.
     *
     * @param iterable<mixed> $array
     * @param callable|string|array $groupBy
     * @return array<mixed>
     */
    public static function groupBy(iterable $array, callable|string|array $groupBy, bool $preserveKeys = false): array
    {
        if (is_array($groupBy) && count($groupBy) > 1) {
            $nextGroups = $groupBy;
            $currentGroup = array_shift($nextGroups);
        } else {
            $currentGroup = is_array($groupBy) ? reset($groupBy) : $groupBy;
            $nextGroups = [];
        }

        $results = [];

        foreach ($array as $key => $value) {
            $groupKey = is_callable($currentGroup)
                ? $currentGroup($value, $key)
                : self::dataGet($value, $currentGroup);

            if (!array_key_exists($groupKey, $results)) {
                $results[$groupKey] = [];
            }

            if ($preserveKeys) {
                $results[$groupKey][$key] = $value;
            } else {
                $results[$groupKey][] = $value;
            }
        }

        if (!empty($nextGroups)) {
            foreach ($results as $groupKey => $group) {
                $results[$groupKey] = self::groupBy($group, $nextGroups, $preserveKeys);
            }
        }

        return $results;
    }

    /**
     * Key an associative array by a field or using a callback.
     *
     * @param iterable<mixed> $array
     * @param callable|string|array $keyBy
     * @return array<mixed>
     */
    public static function keyBy(iterable $array, callable|string|array $keyBy): array
    {
        $results = [];

        foreach ($array as $key => $item) {
            $resolvedKey = is_callable($keyBy)
                ? $keyBy($item, $key)
                : self::dataGet($item, $keyBy);

            if (is_object($resolvedKey) && method_exists($resolvedKey, '__toString')) {
                $resolvedKey = (string) $resolvedKey;
            }

            $results[$resolvedKey] = $item;
        }

        return $results;
    }

    /**
     * Sort the array by the given callback or key.
     *
     * @param array<mixed> $array
     * @param callable|string|array $callback
     * @return array<mixed>
     */
    public static function sortBy(array $array, callable|string|array $callback, int $options = SORT_REGULAR, bool $descending = false): array
    {
        $results = [];

        $callback = self::valueRetriever($callback);

        foreach ($array as $key => $value) {
            $results[$key] = $callback($value, $key);
        }

        $descending ? arsort($results, $options) : asort($results, $options);

        foreach (array_keys($results) as $key) {
            $results[$key] = $array[$key];
        }

        return $results;
    }

    /**
     * Sort the array in descending order by the given callback or key.
     *
     * @param array<mixed> $array
     * @param callable|string|array $callback
     * @return array<mixed>
     */
    public static function sortByDesc(array $array, callable|string|array $callback, int $options = SORT_REGULAR): array
    {
        return self::sortBy($array, $callback, $options, true);
    }

    /**
     * Return unique items from the array.
     *
     * @param iterable<mixed> $array
     * @return array<mixed>
     */
    public static function unique(iterable $array, callable|string|null $key = null): array
    {
        if ($key === null) {
            return array_unique(self::toArray($array), SORT_REGULAR);
        }

        $callback = self::valueRetriever($key);
        $exists = [];
        $results = [];

        foreach ($array as $k => $item) {
            $id = $callback($item, $k);

            if (!in_array($id, $exists, true)) {
                $exists[] = $id;
                $results[$k] = $item;
            }
        }

        return $results;
    }

    /**
     * Collapse an array of arrays into a single array.
     *
     * @param iterable<mixed> $array
     * @return array<mixed>
     */
    public static function collapse(iterable $array): array
    {
        $results = [];

        foreach ($array as $values) {
            if (!is_array($values)) {
                continue;
            }

            $results[] = $values;
        }

        return array_merge([], ...$results);
    }

    /**
     * Push an item onto the beginning of an array.
     *
     * @param array<mixed> $array
     * @return array<mixed>
     */
    public static function prepend(array $array, mixed $value, string|int|null $key = null): array
    {
        if ($key === null) {
            array_unshift($array, $value);
        } else {
            $array = [$key => $value] + $array;
        }

        return $array;
    }

    /**
     * Get a value from the array, and remove it.
     *
     * @param array<mixed> $array
     */
    public static function pull(array &$array, string|int $key, mixed $default = null): mixed
    {
        $value = self::get($array, $key, $default);

        self::forget($array, $key);

        return $value;
    }

    /**
     * Get one or a specified number of random values from an array.
     *
     * @param array<mixed> $array
     * @return mixed|array<mixed>
     */
    public static function random(array $array, ?int $number = null, bool $preserveKeys = false): mixed
    {
        $requested = $number ?? 1;
        $count = count($array);

        if ($requested > $count) {
            throw new InvalidArgumentException(
                "You requested {$requested} items, but there are only {$count} items available."
            );
        }

        if ($number === null) {
            return $array[array_rand($array)];
        }

        if ($number === 0) {
            return [];
        }

        $keys = array_rand($array, $number);
        $results = [];

        if ($preserveKeys) {
            foreach ((array) $keys as $key) {
                $results[$key] = $array[$key];
            }
        } else {
            foreach ((array) $keys as $key) {
                $results[] = $array[$key];
            }
        }

        return $results;
    }

    /**
     * Shuffle the given array and return the result.
     *
     * @param array<mixed> $array
     * @return array<mixed>
     */
    public static function shuffle(array $array): array
    {
        shuffle($array);
        return $array;
    }

    /**
     * Determine if any of the values in the array pass the callback test.
     *
     * @param iterable<mixed> $array
     * @param callable $callback fn(mixed $value, mixed $key): bool
     */
    public static function any(iterable $array, callable $callback): bool
    {
        // Use PHP 8.4's array_any if available
        if (is_array($array) && function_exists('array_any')) {
            return array_any($array, $callback);
        }

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if all values in the array pass the callback test.
     *
     * @param iterable<mixed> $array
     * @param callable $callback fn(mixed $value, mixed $key): bool
     */
    public static function all(iterable $array, callable $callback): bool
    {
        // Use PHP 8.4's array_all if available
        if (is_array($array) && function_exists('array_all')) {
            return array_all($array, $callback);
        }

        foreach ($array as $key => $value) {
            if (!$callback($value, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Divide an array into two arrays: keys and values.
     *
     * @param array<mixed> $array
     * @return array{0: array<mixed>, 1: array<mixed>}
     */
    public static function divide(array $array): array
    {
        return [array_keys($array), array_values($array)];
    }

    /**
     * Cross join the given arrays, returning all possible permutations.
     *
     * @param iterable<mixed> ...$arrays
     * @return array<array<mixed>>
     */
    public static function crossJoin(iterable ...$arrays): array
    {
        $results = [[]];

        foreach ($arrays as $index => $array) {
            $append = [];

            foreach ($results as $product) {
                foreach ($array as $item) {
                    $product[$index] = $item;
                    $append[] = $product;
                }
            }

            $results = $append;
        }

        return $results;
    }

    /**
     * Get the items in the array where the key is between the given range.
     *
     * @param array<mixed> $array
     * @return array<mixed>
     */
    public static function slice(array $array, int $offset, ?int $length = null, bool $preserveKeys = false): array
    {
        return array_slice($array, $offset, $length, $preserveKeys);
    }

    /**
     * Map a callback to each item in the array.
     *
     * @param array<mixed> $array
     * @param callable $callback fn(mixed $value, mixed $key): mixed
     * @return array<mixed>
     */
    public static function map(array $array, callable $callback): array
    {
        $keys = array_keys($array);
        $items = array_map($callback, $array, $keys);

        return array_combine($keys, $items);
    }

    /**
     * Map a callback over the items and their keys.
     *
     * @param array<mixed> $array
     * @param callable $callback fn(mixed $value, mixed $key): array{0: mixed, 1: mixed}
     * @return array<mixed>
     */
    public static function mapWithKeys(array $array, callable $callback): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $assoc = $callback($value, $key);

            foreach ($assoc as $mapKey => $mapValue) {
                $result[$mapKey] = $mapValue;
            }
        }

        return $result;
    }

    /**
     * Recursively merge arrays using a callback for duplicate keys.
     *
     * @param array<mixed> $array1
     * @param array<mixed> $array2
     * @param callable $callback fn(mixed $a, mixed $b): mixed
     * @return array<mixed>
     */
    public static function mergeRecursiveDistinct(array $array1, array $array2, ?callable $callback = null): array
    {
        $merged = $array1;

        foreach ($array2 as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = self::mergeRecursiveDistinct($merged[$key], $value, $callback);
            } elseif (isset($merged[$key]) && $callback !== null) {
                $merged[$key] = $callback($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Determine whether the given value is array accessible.
     */
    public static function accessible(mixed $value): bool
    {
        return is_array($value) || $value instanceof ArrayAccess;
    }

    /**
     * Determine if the given key exists in the provided array.
     *
     * @param array<mixed>|ArrayAccess<mixed, mixed> $array
     */
    public static function exists(array|ArrayAccess $array, string|int $key): bool
    {
        if ($array instanceof ArrayAccess) {
            return $array->offsetExists($key);
        }

        return array_key_exists($key, $array);
    }

    /**
     * Check if an array is associative (has string keys).
     *
     * @param array<mixed> $array
     */
    public static function isAssoc(array $array): bool
    {
        return !array_is_list($array);
    }

    /**
     * Check if an array is a list (sequential integer keys starting from 0).
     *
     * @param array<mixed> $array
     */
    public static function isList(array $array): bool
    {
        return array_is_list($array);
    }

    /**
     * Convert iterable to array.
     *
     * @param iterable<mixed> $iterable
     * @return array<mixed>
     */
    public static function toArray(iterable $iterable): array
    {
        return is_array($iterable) ? $iterable : iterator_to_array($iterable);
    }

    /**
     * Get an item from an array or object using "dot" notation.
     */
    private static function dataGet(mixed $target, string|array|int|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $target;
        }

        $key = is_array($key) ? $key : explode('.', (string) $key);

        foreach ($key as $segment) {
            if (is_array($target) && self::exists($target, $segment)) {
                $target = $target[$segment];
            } elseif ($target instanceof ArrayAccess && $target->offsetExists($segment)) {
                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->{$segment})) {
                $target = $target->{$segment};
            } else {
                return $default;
            }
        }

        return $target;
    }

    /**
     * Get a value retriever callback.
     *
     * @param callable|string|array|null $value
     */
    private static function valueRetriever(callable|string|array|null $value): callable
    {
        if (is_callable($value)) {
            return $value;
        }

        return fn ($item) => self::dataGet($item, $value);
    }

    /**
     * Explode the pluck parameters.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function explodePluckParameters(string|array|int|null $value, string|array|null $key): array
    {
        $value = is_string($value) ? $value : null;
        $key = $key === null || is_array($key) ? null : $key;

        return [$value, $key];
    }
}
