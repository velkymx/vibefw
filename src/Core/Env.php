<?php

declare(strict_types=1);

namespace Fw\Core;

use RuntimeException;

/**
 * Environment variable loader and accessor.
 */
final class Env
{
    private static ?Env $instance = null;

    private bool $loaded = false;

    private array $variables = [];

    /**
     * Keys injected into $_ENV by this loader.
     *
     * @var array<string, true>
     */
    private array $exportedKeys = [];

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public static function setInstance(self $env): void
    {
        self::$instance = $env;
    }

    public static function load(string $path): void
    {
        self::getInstance()->loadVars($path);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::getInstance()->getVar($key, $default);
    }

    public static function string(string $key, string $default = ''): string
    {
        return self::getInstance()->getString($key, $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        return self::getInstance()->getInt($key, $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return self::getInstance()->getBool($key, $default);
    }

    public static function array(string $key, array $default = []): array
    {
        return self::getInstance()->getArray($key, $default);
    }

    public static function has(string $key): bool
    {
        return self::getInstance()->hasVar($key);
    }

    public static function require(string $key): mixed
    {
        return self::getInstance()->requireVar($key);
    }

    public static function isLoaded(): bool
    {
        return self::getInstance()->isLoadedVar();
    }

    public static function clear(): void
    {
        self::getInstance()->resetVars();
    }

    public function loadVars(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
                // Process standard escape sequences used in double-quoted .env values.
                // Must process \\ before \" to avoid double-unescaping.
                $value = str_replace(['\\\\', '\\"', '\\n', '\\r', '\\t'], ['\\', '"', "\n", "\r", "\t"], $value);
            } elseif (str_starts_with($value, "'") && str_ends_with($value, "'")) {
                $value = substr($value, 1, -1);
                // Single-quoted values: only \' is an escape sequence (POSIX-style).
                $value = str_replace("\\'", "'", $value);
            }

            if (array_key_exists($key, $_ENV) || getenv($key) !== false) {
                continue;
            }

            $this->variables[$key] = $value;
            $_ENV[$key] = $value;
            $this->exportedKeys[$key] = true;
        }

        $this->loaded = true;
    }

    public function getVar(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_ENV)) {
            return $this->normalizeValue($_ENV[$key]);
        }

        $value = getenv($key);
        if ($value !== false) {
            return $this->castValue($value);
        }

        if (array_key_exists($key, $this->variables)) {
            return $this->castValue($this->variables[$key]);
        }

        return $default;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->getVar($key);
        return $value !== null ? (string) $value : $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->getVar($key);
        return $value !== null ? (int) $value : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->getVar($key);
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower((string) $value);
        return in_array($value, ['true', '1', 'yes', 'on'], true);
    }

    public function getArray(string $key, array $default = []): array
    {
        $value = $this->getVar($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return array_map('trim', explode(',', (string) $value));
    }

    public function hasVar(string $key): bool
    {
        return $this->getVar($key) !== null;
    }

    public function requireVar(string $key): mixed
    {
        $value = $this->getVar($key);
        if ($value === null) {
            throw new RuntimeException("Required environment variable '{$key}' is not set");
        }
        return $value;
    }

    public function isLoadedVar(): bool
    {
        return $this->loaded;
    }

    public function resetVars(): void
    {
        foreach (array_keys($this->exportedKeys) as $key) {
            unset($_ENV[$key]);
        }

        $this->variables = [];
        $this->exportedKeys = [];
        $this->loaded = false;
    }

    private function normalizeValue(mixed $value): mixed
    {
        return is_string($value) ? $this->castValue($value) : $value;
    }

    private function castValue(string $value): mixed
    {
        $lower = strtolower($value);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null' || $lower === '') {
            return null;
        }
        // Use strict patterns instead of is_numeric(), which also accepts scientific
        // notation (1e5), hex (0x1A), and signed values (+5) that .env authors never intend.
        if (preg_match('/^-?[0-9]+$/', $value)) {
            $intVal = (int) $value;
            // Guard against integer overflow: if casting to int changes the value,
            // keep as string to avoid silent data loss.
            if ((string) $intVal !== $value) {
                return $value;
            }
            return $intVal;
        }
        if (preg_match('/^-?[0-9]+\.[0-9]+$/', $value)) {
            return (float) $value;
        }
        return $value;
    }
}

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}
