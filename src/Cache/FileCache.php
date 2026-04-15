<?php

declare(strict_types=1);

namespace Fw\Cache;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * File-based cache driver.
 *
 * Persists cache across requests using the filesystem.
 * Good for development and single-server deployments.
 */
final class FileCache implements CacheInterface
{
    private string $path;

    private int $defaultTtl;

    public function __construct(string $path, int $defaultTtl = 3600)
    {
        $this->path = rtrim($path, '/');
        $this->defaultTtl = $defaultTtl;

        // Handle race condition: another process might create the directory
        if (!is_dir($this->path) && !@mkdir($this->path, 0o750, true) && !is_dir($this->path)) {
            throw new RuntimeException("Failed to create cache directory: {$this->path}");
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return $default;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return $default;
        }

        $data = json_decode($content, true);

        // Use array_key_exists, not isset: isset() returns false for null values,
        // which breaks no-TTL entries (expires=null) and explicitly-cached null values.
        if (!is_array($data) || !array_key_exists('expires', $data) || !array_key_exists('value', $data)) {
            // Invalid cache format - delete and return default
            $this->delete($key);
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
        $file = $this->getFilePath($key);
        $dir = dirname($file);

        // Create directory if needed - handle race condition properly
        // mkdir with recursive=true returns false if dir already exists,
        // so we check is_dir after to handle concurrent creation
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            return false;
        }

        $data = [
            'value' => $value,
            // null TTL means "store forever" (PSR-16 compliance).
            // Pass an explicit integer to use a specific TTL; omit/null to persist indefinitely.
            'expires' => $ttl !== null ? time() + $ttl : null,
        ];

        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return file_put_contents($file, $json, LOCK_EX) !== false;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    public function delete(string $key): bool
    {
        $file = $this->getFilePath($key);

        if (file_exists($file)) {
            return unlink($file);
        }

        return true;
    }

    public function clear(): bool
    {
        $this->deleteDirectory($this->path);
        mkdir($this->path, 0o750, true);
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
     * Atomically increment a numeric value.
     *
     * Uses a dedicated *.lock sidecar file so the exclusive lock is held on a
     * stable inode. Locking the data file directly suffers from a TOCTOU: if the
     * data file is deleted between fopen() and flock(), the lock is on a deleted
     * inode and subsequent writes go to a ghost file that is never accessible by
     * name again.
     */
    public function increment(string $key, int $step = 1, ?int $ttl = null): int|false
    {
        $file = $this->getFilePath($key);
        $lockFile = $file . '.lock';
        $dir = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            return false;
        }

        // Acquire an exclusive lock on the sidecar lock file.
        // The lock file is never deleted, so its inode is always stable.
        $lockFh = fopen($lockFile, 'c');
        if ($lockFh === false) {
            return false;
        }

        if (!flock($lockFh, LOCK_EX)) {
            fclose($lockFh);
            return false;
        }

        try {
            // Read the data file while holding the lock
            $content = file_exists($file) ? file_get_contents($file) : false;
            $data = ($content !== false && $content !== '') ? json_decode($content, true) : null;

            $expires = null;
            if (is_array($data) && isset($data['expires'], $data['value'])) {
                if ($data['expires'] !== null && $data['expires'] < time()) {
                    $data = null; // Expired — start fresh
                } else {
                    $expires = $data['expires'];
                }
            }

            $current = is_array($data) ? (int) ($data['value'] ?? 0) : 0;
            $newValue = $current + $step;

            if ($expires === null) {
                $expires = $ttl !== null ? time() + $ttl : null;
            }

            $newData = json_encode(
                ['value' => $newValue, 'expires' => $expires],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );

            file_put_contents($file, $newData, LOCK_EX);
        } finally {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }

        return $newValue;
    }

    /**
     * Remove expired cache files.
     */
    public function gc(): int
    {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'cache') {
                $content = file_get_contents($file->getPathname());
                if ($content !== false) {
                    $data = json_decode($content, true);
                    // Only GC entries with a non-null expiry that is in the past.
                    // Entries with null expires are stored forever and must not be collected.
                    if (is_array($data) && array_key_exists('expires', $data)
                        && $data['expires'] !== null && $data['expires'] < time()) {
                        @unlink($file->getPathname());
                        // Also clean up sidecar lock file if present
                        $lockFile = $file->getPathname() . '.lock';
                        if (file_exists($lockFile)) {
                            @unlink($lockFile);
                        }
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    private function getFilePath(string $key): string
    {
        // Use SHA256 for collision resistance (SHA1 is cryptographically broken)
        $hash = hash('sha256', $key);
        $dir = substr($hash, 0, 2);
        return "{$this->path}/{$dir}/{$hash}.cache";
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
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
