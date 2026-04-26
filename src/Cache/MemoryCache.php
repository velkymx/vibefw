<?php

declare(strict_types=1);

namespace Fw\Cache;

use Fw\Support\ResettableInterface;

/**
 * In-memory cache driver.
 *
 * Fast but only persists for the current request.
 * Ideal for caching repeated queries within a single request.
 */
final class MemoryCache implements CacheInterface, ResettableInterface
{
    /** @var array<string, array{value: mixed, expires: ?int}> */
    private array $store = [];

    private int $hits = 0;

    private int $misses = 0;

    /**
     * Reset the cache state (implements ResettableInterface).
     */
    public function reset(): void
    {
        $this->clear();
        $this->hits = 0;
        $this->misses = 0;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!isset($this->store[$key])) {
            $this->misses++;
            return $default;
        }

        $item = $this->store[$key];

        if ($item['expires'] !== null && $item['expires'] < time()) {
            unset($this->store[$key]);
            $this->misses++;
            return $default;
        }

        $this->hits++;
        return $item['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->store[$key] = [
            'value' => $value,
            'expires' => $ttl !== null ? time() + $ttl : null,
        ];
        return true;
    }

    public function has(string $key): bool
    {
        if (!isset($this->store[$key])) {
            return false;
        }

        $item = $this->store[$key];

        if ($item['expires'] !== null && $item['expires'] < time()) {
            unset($this->store[$key]);
            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        return true;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function getMany(array $keys): array
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }
        return $results;
    }

    public function setMany(array $values, ?int $ttl = null): bool
    {
        $allOk = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $allOk = false;
            }
        }
        return $allOk;
    }

    /**
     * Atomically increment a numeric value.
     *
     * MemoryCache is single-process (per-request), so no locking is needed.
     */
    public function increment(string $key, int $step = 1, ?int $ttl = null): int|false
    {
        $item = $this->store[$key] ?? null;

        $current = 0;
        $expires = $ttl !== null ? time() + $ttl : null;

        if ($item !== null) {
            // Treat expired items as missing
            if ($item['expires'] !== null && $item['expires'] < time()) {
                unset($this->store[$key]);
            } else {
                $current = (int) $item['value'];
                $expires = $item['expires']; // Preserve existing TTL
            }
        }

        $newValue = $current + $step;
        $this->store[$key] = ['value' => $newValue, 'expires' => $expires];
        return $newValue;
    }

    /**
     * Get cache statistics.
     *
     * @return array{hits: int, misses: int, ratio: float, size: int}
     */
    public function getStats(): array
    {
        $total = $this->hits + $this->misses;
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'ratio' => $total > 0 ? $this->hits / $total : 0.0,
            'size' => count($this->store),
        ];
    }
}
