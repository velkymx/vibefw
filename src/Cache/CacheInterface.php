<?php

declare(strict_types=1);

namespace Fw\Cache;

interface CacheInterface
{
    /**
     * Get an item from the cache.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Store an item in the cache.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Check if an item exists in the cache.
     */
    public function has(string $key): bool;

    /**
     * Remove an item from the cache.
     */
    public function delete(string $key): bool;

    /**
     * Clear all items from the cache.
     */
    public function clear(): bool;

    /**
     * Get an item from the cache, or store a default value.
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed;

    /**
     * Get multiple items from the cache.
     *
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public function getMany(array $keys): array;

    /**
     * Store multiple items in the cache.
     *
     * @param array<string, mixed> $values
     */
    public function setMany(array $values, ?int $ttl = null): bool;

    /**
     * Atomically increment a numeric value.
     *
     * If the key does not exist, it is initialised to 0 before incrementing.
     * Returns the new value, or false on failure.
     */
    public function increment(string $key, int $step = 1, ?int $ttl = null): int|false;
}
