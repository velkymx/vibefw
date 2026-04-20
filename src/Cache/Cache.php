<?php

declare(strict_types=1);

namespace Fw\Cache;

/**
 * Cache manager with layered caching support.
 *
 * Uses memory cache as L1 (fastest, single request) and
 * a persistent store (APCu or File) as L2 (shared across requests).
 */
final class Cache implements CacheInterface
{
    private MemoryCache $l1;

    private CacheInterface $l2;

    private bool $useL2;

    /**
     * Private miss marker used as the `$default` to L2::get(). Caller code can
     * never observe this object, so a stored value can never === it by mistake.
     */
    private static ?object $missMarker = null;

    private static function missMarker(): object
    {
        return self::$missMarker ??= new \stdClass();
    }

    public function __construct(?CacheInterface $store = null, string $cachePath = '')
    {
        $this->l1 = new MemoryCache();

        if ($store !== null) {
            $this->l2 = $store;
            $this->useL2 = true;
        } elseif (ApcuCache::isAvailable()) {
            $this->l2 = new ApcuCache();
            $this->useL2 = true;
        } elseif ($cachePath !== '') {
            $this->l2 = new FileCache($cachePath);
            $this->useL2 = true;
        } else {
            $this->l2 = new MemoryCache(); // Fallback to memory only
            $this->useL2 = false;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // Check L1 first
        if ($this->l1->has($key)) {
            return $this->l1->get($key);
        }

        // Check L2
        if ($this->useL2) {
            $marker = self::missMarker();
            $value = $this->l2->get($key, $marker);
            if ($value !== $marker) {
                // Promote to L1
                $this->l1->set($key, $value);
                return $value;
            }
        }

        return $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->l1->set($key, $value, $ttl);

        if ($this->useL2) {
            return $this->l2->set($key, $value, $ttl);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return $this->l1->has($key) || ($this->useL2 && $this->l2->has($key));
    }

    public function delete(string $key): bool
    {
        $this->l1->delete($key);

        if ($this->useL2) {
            return $this->l2->delete($key);
        }

        return true;
    }

    public function clear(): bool
    {
        $this->l1->clear();

        if ($this->useL2) {
            return $this->l2->clear();
        }

        return true;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        // Check L1
        if ($this->l1->has($key)) {
            return $this->l1->get($key);
        }

        // Check L2
        if ($this->useL2) {
            $marker = self::missMarker();
            $value = $this->l2->get($key, $marker);
            if ($value !== $marker) {
                $this->l1->set($key, $value, $ttl);
                return $value;
            }
        }

        // Compute and store
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function getMany(array $keys): array
    {
        $results = [];
        $missing = [];

        // Check L1
        foreach ($keys as $key) {
            if ($this->l1->has($key)) {
                $results[$key] = $this->l1->get($key);
            } else {
                $missing[] = $key;
            }
        }

        // Check L2 for missing
        if ($this->useL2 && !empty($missing)) {
            $l2Results = $this->l2->getMany($missing);
            foreach ($l2Results as $key => $value) {
                if ($value !== null) {
                    $results[$key] = $value;
                    $this->l1->set($key, $value);
                }
            }
        }

        return $results;
    }

    public function setMany(array $values, ?int $ttl = null): bool
    {
        $this->l1->setMany($values, $ttl);

        if ($this->useL2) {
            return $this->l2->setMany($values, $ttl);
        }

        return true;
    }

    public function increment(string $key, int $step = 1, ?int $ttl = null): int|false
    {
        // Delegate to L2 for atomicity (L1 memory cache is per-process only)
        if ($this->useL2) {
            $result = $this->l2->increment($key, $step, $ttl);
            if ($result !== false) {
                // Update L1 to reflect the new value
                $this->l1->set($key, $result, $ttl);
            }
            return $result;
        }

        return $this->l1->increment($key, $step, $ttl);
    }

    /**
     * Get or set using tags for grouped invalidation.
     */
    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache($this, $tags);
    }

    /**
     * Get cache statistics.
     */
    public function getStats(): array
    {
        $stats = [
            'l1' => $this->l1->getStats(),
        ];

        if ($this->useL2 && method_exists($this->l2, 'getStats')) {
            $stats['l2'] = $this->l2->getStats();
        }

        return $stats;
    }

    /**
     * Get the L1 (memory) cache instance.
     */
    public function getL1(): MemoryCache
    {
        return $this->l1;
    }

    /**
     * Get the L2 (persistent) cache instance.
     */
    public function getL2(): CacheInterface
    {
        return $this->l2;
    }
}
