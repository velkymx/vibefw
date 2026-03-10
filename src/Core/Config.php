<?php

declare(strict_types=1);

namespace Fw\Core;

use Closure;
use RuntimeException;

/**
 * Configuration repository.
 *
 * Handles loading environment variables and configuration files,
 * and provides dot-notation access to configuration values.
 *
 * Supports caching for production environments to avoid re-reading
 * config files on every request.
 *
 * @example
 *     $config = new Config(BASE_PATH);
 *     $debug = $config->get('app.debug', false);
 *     $dbHost = $config->get('database.host', 'localhost');
 */
final class Config
{
    /**
     * Loaded configuration values.
     * @var array<string, mixed>
     */
    private array $config = [];

    /**
     * Base path for configuration files.
     */
    private string $basePath;

    /**
     * Whether configuration has been loaded.
     */
    private bool $loaded = false;

    /**
     * Cache file path.
     */
    private ?string $cacheFile = null;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
        $this->cacheFile = $this->basePath . '/storage/cache/config.php';
    }

    /**
     * Load environment variables and configuration files.
     */
    public function load(): self
    {
        if ($this->loaded) {
            return $this;
        }

        // Always load env first (may affect config values)
        $this->loadEnv();

        // Try loading from cache first
        if ($this->loadFromCache()) {
            $this->loaded = true;
            return $this;
        }

        // Fall back to loading config files
        $this->loadConfigFiles();
        $this->loaded = true;

        return $this;
    }

    /**
     * Save current configuration to cache file.
     *
     * @throws RuntimeException If config contains non-serializable values (objects, resources)
     */
    public function saveCache(): bool
    {
        if ($this->cacheFile === null) {
            return false;
        }

        // Security: Validate config contains only safe types before var_export()
        // Objects could have __wakeup()/__destruct() that execute arbitrary code
        $this->assertSafeForExport($this->config, 'config');

        $dir = dirname($this->cacheFile);
        // Three-phase mkdir to handle race where another process creates the directory
        // between the is_dir() check and mkdir() — a common source of false negatives.
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            return false;
        }

        $content = "<?php\n\n// Generated config cache - do not edit\n// Regenerate with: php fw config:cache\n\nreturn " .
            var_export($this->config, true) . ";\n";

        $result = file_put_contents($this->cacheFile, $content, LOCK_EX);

        // Invalidate OPcache
        if ($result !== false && function_exists('opcache_invalidate')) {
            opcache_invalidate($this->cacheFile, true);
        }

        return $result !== false;
    }

    /**
     * Clear the configuration cache.
     */
    public function clearCache(): bool
    {
        if ($this->cacheFile === null || !file_exists($this->cacheFile)) {
            return true;
        }

        $result = unlink($this->cacheFile);

        if ($result && function_exists('opcache_invalidate')) {
            opcache_invalidate($this->cacheFile, true);
        }

        return $result;
    }

    /**
     * Check if configuration is cached.
     */
    public function isCached(): bool
    {
        return $this->cacheFile !== null && file_exists($this->cacheFile);
    }

    /**
     * Get a configuration value using dot notation.
     *
     * @example
     *     $config->get('app.debug');
     *     $config->get('database.host', 'localhost');
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Check if a configuration key exists.
     */
    public function has(string $key): bool
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return false;
            }
            $value = $value[$k];
        }

        return true;
    }

    /**
     * Set a configuration value at runtime.
     *
     * Note: This does not persist the value to disk.
     */
    public function set(string $key, mixed $value): self
    {
        $keys = explode('.', $key);
        $config = &$this->config;

        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $config[$k] = $value;
            } else {
                if (!isset($config[$k]) || !is_array($config[$k])) {
                    $config[$k] = [];
                }
                $config = &$config[$k];
            }
        }

        return $this;
    }

    /**
     * Get all configuration values.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * Get a top-level configuration section.
     *
     * @return array<string, mixed>
     */
    public function section(string $name): array
    {
        return $this->config[$name] ?? [];
    }

    /**
     * Load configuration from cache file.
     */
    private function loadFromCache(): bool
    {
        if ($this->cacheFile === null || !file_exists($this->cacheFile)) {
            return false;
        }

        $cached = require $this->cacheFile;

        if (!is_array($cached)) {
            return false;
        }

        $this->config = $cached;
        return true;
    }

    /**
     * Recursively verify that a value contains only safe types for var_export.
     *
     * Safe types: null, bool, int, float, string, array (containing only safe types)
     * Unsafe types: objects (can have __wakeup/__destruct), resources, closures
     *
     * @throws RuntimeException If unsafe types are found
     */
    private function assertSafeForExport(mixed $value, string $path): void
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return; // Safe scalar types
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->assertSafeForExport($item, "{$path}.{$key}");
            }
            return;
        }

        if (is_object($value)) {
            $class = get_class($value);
            throw new RuntimeException(
                "Config cache security error: Object of class '{$class}' found at '{$path}'. " .
                "Objects cannot be safely cached as they may execute arbitrary code on deserialization. " .
                "Remove the object or convert it to an array/scalar."
            );
        }

        if (is_resource($value)) {
            throw new RuntimeException(
                "Config cache error: Resource found at '{$path}'. Resources cannot be cached."
            );
        }

        // Closures are objects, but be explicit
        if ($value instanceof Closure) {
            throw new RuntimeException(
                "Config cache error: Closure found at '{$path}'. Closures cannot be cached."
            );
        }

        throw new RuntimeException(
            "Config cache error: Unknown type '" . gettype($value) . "' found at '{$path}'."
        );
    }

    /**
     * Load environment variables from .env file.
     */
    private function loadEnv(): void
    {
        $envFile = $this->basePath . '/.env';

        if (file_exists($envFile)) {
            Env::load($envFile);
        }
    }

    /**
     * Load configuration files from config directory.
     */
    private function loadConfigFiles(): void
    {
        $configPath = $this->basePath . '/config';

        $configFiles = [
            'app',
            'database',
            'queue',
            'lifecycle',
            'cache',
            'mail',
        ];

        foreach ($configFiles as $file) {
            $filePath = $configPath . '/' . $file . '.php';

            if (file_exists($filePath)) {
                $this->config[$file] = require $filePath;
            }
        }
    }
}
