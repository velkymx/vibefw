<?php

declare(strict_types=1);

namespace Fw\Cache;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * OPcache-backed data cache.
 *
 * Stores cached data as PHP files using var_export(), which OPcache
 * then caches as compiled bytecode. Much faster than serialize/unserialize
 * for simple data types (strings, arrays).
 */
final class OpcacheCache implements CacheInterface
{
    private string $path;

    private int $defaultTtl;

    public function __construct(string $path, int $defaultTtl = 3600)
    {
        $this->path = rtrim($path, '/');
        $this->defaultTtl = $defaultTtl;

        if (!is_dir($this->path)) {
            mkdir($this->path, 0o755, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return $default;
        }

        $data = include $file;

        if (!is_array($data) || !isset($data['expires'], $data['value'])) {
            return $default;
        }

        if ($data['expires'] !== null && $data['expires'] < time()) {
            $this->delete($key);
            return $default;
        }

        return $data['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        // Validate value is safe for var_export (prevents code injection)
        $this->assertSafeForExport($value);

        $file = $this->getFilePath($key);
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        // PSR-16: null TTL means "store indefinitely". A non-null TTL of 0 expires immediately.
        $expires = $ttl !== null ? time() + $ttl : null;

        $data = [
            'expires' => $expires,
            'value' => $value,
        ];

        $content = '<?php return ' . var_export($data, true) . ';';

        // Write to temp file then rename for atomicity
        $temp = $file . '.' . uniqid('', true) . '.tmp';

        if (file_put_contents($temp, $content, LOCK_EX) === false) {
            return false;
        }

        // On Windows, rename() fails if destination exists
        // Delete destination first, accepting brief window of inconsistency
        if (PHP_OS_FAMILY === 'Windows' && file_exists($file)) {
            @unlink($file);
        }

        if (!rename($temp, $file)) {
            // Fallback: try copy + delete on Windows if rename still fails
            if (PHP_OS_FAMILY === 'Windows') {
                if (copy($temp, $file)) {
                    @unlink($temp);
                } else {
                    @unlink($temp);
                    return false;
                }
            } else {
                @unlink($temp);
                return false;
            }
        }

        // Invalidate OPcache for this file so it picks up new content
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    public function delete(string $key): bool
    {
        $file = $this->getFilePath($key);

        if (file_exists($file)) {
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
            return unlink($file);
        }

        return true;
    }

    public function clear(): bool
    {
        $this->deleteDirectory($this->path);
        mkdir($this->path, 0o755, true);
        return true;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $value = $this->get($key, $this);

        if ($value !== $this) {
            return $value;
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
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    /**
     * Increment a numeric value.
     *
     * Uses a sidecar lock file for atomicity (same pattern as FileCache).
     * For high-concurrency counters, prefer ApcuCache which uses apcu_inc().
     */
    public function increment(string $key, int $step = 1, ?int $ttl = null): int|false
    {
        $file = $this->getFilePath($key);
        $lockFile = $file . '.lock';
        $dir = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return false;
        }

        $lockFh = fopen($lockFile, 'c');
        if ($lockFh === false) {
            return false;
        }

        if (!flock($lockFh, LOCK_EX)) {
            fclose($lockFh);
            return false;
        }

        try {
            $current = 0;
            $expires = $ttl !== null ? time() + $ttl : null;

            if (file_exists($file)) {
                $data = @include $file;
                if (is_array($data) && isset($data['value'])) {
                    if ($data['expires'] !== null && $data['expires'] < time()) {
                        // Expired — start fresh
                    } else {
                        $current = (int) $data['value'];
                        // Preserve existing expiry if no new TTL given
                        if ($ttl === null && isset($data['expires'])) {
                            $expires = $data['expires'];
                        }
                    }
                }
            }

            $newValue = $current + $step;
            $this->assertSafeForExport($newValue);

            $content = '<?php return ' . var_export(['expires' => $expires, 'value' => $newValue], true) . ';';

            $temp = tempnam($dir, 'opc_');
            if ($temp === false) {
                return false;
            }

            file_put_contents($temp, $content, LOCK_EX);

            if (PHP_OS_FAMILY === 'Windows' && file_exists($file)) {
                @unlink($file);
            }

            rename($temp, $file);

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }

            return $newValue;
        } finally {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }
    }

    private function getFilePath(string $key): string
    {
        $hash = hash('sha256', $key);
        $dir = substr($hash, 0, 2);
        return "{$this->path}/{$dir}/{$hash}.php";
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($file->getPathname(), true);
                }
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }

    /**
     * Validate that a value is safe for var_export().
     *
     * var_export() can produce executable PHP code for objects with
     * __set_state() methods, potentially enabling code injection.
     * Only allow scalar values, arrays, and null.
     *
     * @throws InvalidArgumentException If value contains unsafe types
     */
    private function assertSafeForExport(mixed $value, string $path = 'value'): void
    {
        if ($value === null || is_scalar($value)) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->assertSafeForExport($item, "{$path}.{$key}");
            }
            return;
        }

        throw new InvalidArgumentException(
            "Cannot cache value at {$path}: only scalar values, arrays, and null are allowed. " .
            "Got " . get_debug_type($value) . ". Use FileCache or Redis for complex types."
        );
    }
}
