<?php

declare(strict_types=1);

namespace Fw\Core;

/**
 * High-performance view cache using OPcache for compiled templates.
 *
 * This caches rendered view output to avoid re-rendering on every request.
 * Particularly effective for:
 * - Static pages (about, terms, etc.)
 * - Semi-static content with TTL
 * - Fragment caching of expensive partials
 */
final class ViewCache
{
    private string $cachePath;

    private bool $enabled;

    public function __construct(string $cachePath, bool $enabled = true)
    {
        $this->cachePath = rtrim($cachePath, '/');
        $this->enabled = $enabled;

        if ($enabled && !is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0o755, true);
        }
    }

    /**
     * Generate a cache key from view name, data, and optional template file path.
     *
     * Including the template file's mtime ensures the cache is automatically
     * busted when the template file changes after a deployment.
     *
     * @param string      $view     View name (e.g. 'posts.index')
     * @param array       $data     View data
     * @param string|null $filePath Absolute path to the template file for mtime invalidation
     */
    public static function makeKey(string $view, array $data = [], ?string $filePath = null): string
    {
        // Only include serializable, cacheable data
        $cacheableData = array_filter($data, fn ($v) => is_scalar($v) || is_array($v) || is_null($v));

        // Include mtime so cache is automatically invalidated on file change
        $mtime = ($filePath !== null && file_exists($filePath)) ? (string) filemtime($filePath) : '0';

        return md5($view . '|' . $mtime . '|' . json_encode($cacheableData, JSON_THROW_ON_ERROR));
    }

    /**
     * Get cached view content or null if not cached/expired.
     */
    public function get(string $key): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $file = $this->getCacheFile($key);

        if (!file_exists($file)) {
            return null;
        }

        // Check if cache file is still valid
        $data = include $file;

        if (!is_array($data) || !isset($data['expires'], $data['content'])) {
            @unlink($file);
            return null;
        }

        // Check expiration
        if ($data['expires'] !== 0 && $data['expires'] < time()) {
            @unlink($file);
            return null;
        }

        return $data['content'];
    }

    /**
     * Cache view content.
     *
     * @param string $key Cache key
     * @param string $content Rendered content
     * @param int $ttl Time to live in seconds (0 = forever)
     */
    public function set(string $key, string $content, int $ttl = 3600): void
    {
        if (!$this->enabled) {
            return;
        }

        $file = $this->getCacheFile($key);
        $expires = $ttl > 0 ? time() + $ttl : 0;

        // Store as PHP file for OPcache optimization
        $php = '<?php return ' . var_export([
            'expires' => $expires,
            'content' => $content,
        ], true) . ';';

        // Atomic write using tempnam() for process-safe unique filenames
        $tmp = tempnam(dirname($file), 'view_');
        if ($tmp === false) {
            return;
        }
        file_put_contents($tmp, $php, LOCK_EX);
        rename($tmp, $file);

        // Invalidate OPcache for this file
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
    }

    /**
     * Check if a cache key exists and is valid.
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Remove a cached view.
     */
    public function forget(string $key): void
    {
        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            @unlink($file);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
        }
    }

    /**
     * Clear all cached views.
     */
    public function flush(): void
    {
        $files = glob($this->cachePath . '/*.php');
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            @unlink($file);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
        }
    }

    private function getCacheFile(string $key): string
    {
        return $this->cachePath . '/view_' . $key . '.php';
    }
}
