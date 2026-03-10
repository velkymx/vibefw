<?php

declare(strict_types=1);

namespace Fw\Cache;

/**
 * Tagged cache for grouped invalidation.
 *
 * Allows caching items with tags so you can flush
 * all items with a specific tag at once.
 */
final class TaggedCache implements CacheInterface
{
    private CacheInterface $cache;

    /** @var array<string> */
    private array $tags;

    /**
     * @param array<string> $tags
     */
    public function __construct(CacheInterface $cache, array $tags)
    {
        $this->cache = $cache;
        $this->tags = $tags;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $tagKey = $this->taggedKey($key);
        return $this->cache->get($tagKey, $default);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $tagKey = $this->taggedKey($key);
        return $this->cache->set($tagKey, $value, $ttl);
    }

    public function has(string $key): bool
    {
        return $this->cache->has($this->taggedKey($key));
    }

    public function delete(string $key): bool
    {
        return $this->cache->delete($this->taggedKey($key));
    }

    public function clear(): bool
    {
        // Increment tag versions to invalidate all tagged keys
        foreach ($this->tags as $tag) {
            $this->incrementTagVersion($tag);
        }
        return true;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $tagKey = $this->taggedKey($key);
        return $this->cache->remember($tagKey, $callback, $ttl);
    }

    public function getMany(array $keys): array
    {
        $taggedKeys = array_map(fn ($k) => $this->taggedKey($k), $keys);
        $values = $this->cache->getMany($taggedKeys);

        $results = [];
        foreach ($keys as $i => $key) {
            $results[$key] = $values[$taggedKeys[$i]] ?? null;
        }
        return $results;
    }

    public function setMany(array $values, ?int $ttl = null): bool
    {
        $tagged = [];
        foreach ($values as $key => $value) {
            $tagged[$this->taggedKey($key)] = $value;
        }
        return $this->cache->setMany($tagged, $ttl);
    }

    /**
     * Flush all items with these tags.
     */
    public function flush(): bool
    {
        return $this->clear();
    }

    public function increment(string $key, int $step = 1, ?int $ttl = null): int|false
    {
        return $this->cache->increment($this->taggedKey($key), $step, $ttl);
    }

    /**
     * Generate a tagged cache key.
     */
    private function taggedKey(string $key): string
    {
        $tagVersions = [];
        foreach ($this->tags as $tag) {
            $tagVersions[] = $tag . ':' . $this->getTagVersion($tag);
        }
        return implode('|', $tagVersions) . '|' . $key;
    }

    /**
     * Get the current version for a tag.
     */
    private function getTagVersion(string $tag): int
    {
        $version = $this->cache->get('tag:' . $tag);
        if ($version === null) {
            $version = 1;
            $this->cache->set('tag:' . $tag, $version);
        }
        return (int) $version;
    }

    /**
     * Increment a tag's version to invalidate all its keys.
     */
    private function incrementTagVersion(string $tag): int
    {
        $version = $this->getTagVersion($tag) + 1;
        $this->cache->set('tag:' . $tag, $version);
        return $version;
    }
}
