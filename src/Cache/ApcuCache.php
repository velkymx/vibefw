<?php

declare(strict_types=1);

namespace Fw\Cache;

use APCUIterator;
use RuntimeException;

/**
 * APCu cache driver.
 *
 * High-performance shared memory cache.
 * Persists across requests on the same server.
 * Requires the APCu extension.
 */
final class ApcuCache implements CacheInterface
{
    private string $prefix;

    private int $defaultTtl;

    /**
     * @param int $defaultTtl TTL (seconds) used when a caller passes
     *                        null. Passing 0 directly to APCu means
     *                        "no expiry" on current APCu builds, but
     *                        the framework's own CacheInterface reads
     *                        null as "use driver default", so we
     *                        resolve that default here instead of
     *                        tying framework semantics to APCu-
     *                        specific zero-handling.
     */
    public function __construct(string $prefix = 'fw:', int $defaultTtl = 3600)
    {
        if (!extension_loaded('apcu')) {
            throw new RuntimeException('APCu extension is not loaded');
        }

        if (!apcu_enabled()) {
            throw new RuntimeException('APCu is not enabled');
        }

        $this->prefix = $prefix;
        $this->defaultTtl = $defaultTtl;
    }

    /**
     * Check if APCu is available.
     */
    public static function isAvailable(): bool
    {
        return extension_loaded('apcu') && apcu_enabled();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $success = false;
        $value = apcu_fetch($this->prefix . $key, $success);

        return $success ? $value : $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return apcu_store($this->prefix . $key, $value, $ttl ?? $this->defaultTtl);
    }

    public function has(string $key): bool
    {
        return apcu_exists($this->prefix . $key);
    }

    public function delete(string $key): bool
    {
        return apcu_delete($this->prefix . $key);
    }

    public function clear(): bool
    {
        // Clear only keys with our prefix
        $iterator = new APCUIterator('/^' . preg_quote($this->prefix, '/') . '/');
        return apcu_delete($iterator);
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $prefixedKey = $this->prefix . $key;
        $success = false;
        $value = apcu_fetch($prefixedKey, $success);

        if ($success) {
            return $value;
        }

        $value = $callback();
        apcu_store($prefixedKey, $value, $ttl ?? $this->defaultTtl);
        return $value;
    }

    public function getMany(array $keys): array
    {
        $prefixedKeys = array_map(fn ($k) => $this->prefix . $k, $keys);
        $values = apcu_fetch($prefixedKeys);

        $results = [];
        foreach ($keys as $i => $key) {
            $results[$key] = $values[$prefixedKeys[$i]] ?? null;
        }
        return $results;
    }

    public function setMany(array $values, ?int $ttl = null): bool
    {
        $prefixed = [];
        foreach ($values as $key => $value) {
            $prefixed[$this->prefix . $key] = $value;
        }
        $errors = apcu_store($prefixed, null, $ttl ?? $this->defaultTtl);
        return empty($errors);
    }

    /**
     * Atomically increment a numeric value.
     *
     * APCu's apcu_inc is natively atomic, so no locking is needed.
     */
    public function increment(string $key, int $step = 1, ?int $ttl = null): int|false
    {
        $prefixedKey = $this->prefix . $key;

        // Initialise if missing so the first increment starts from $step
        if (!apcu_exists($prefixedKey)) {
            apcu_store($prefixedKey, 0, $ttl ?? $this->defaultTtl);
        }

        return apcu_inc($prefixedKey, $step);
    }

    /**
     * Decrement a numeric value.
     */
    public function decrement(string $key, int $step = 1): int|false
    {
        return apcu_dec($this->prefix . $key, $step);
    }

    /**
     * Get APCu cache info.
     */
    public function getStats(): array
    {
        $info = apcu_cache_info();
        $sma = apcu_sma_info();

        return [
            'hits' => $info['num_hits'] ?? 0,
            'misses' => $info['num_misses'] ?? 0,
            'entries' => $info['num_entries'] ?? 0,
            'memory_size' => $info['mem_size'] ?? 0,
            'memory_available' => $sma['avail_mem'] ?? 0,
        ];
    }
}
