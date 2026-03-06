<?php

declare(strict_types=1);

namespace Fw\Core;

/**
 * Environment variable loader and accessor.
 */
final class Env
{
    private static ?Env $instance = null;

    private bool $loaded = false;
    private array $variables = [];

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public static function setInstance(self $env): void
    {
        self::$instance = $env;
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

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $this->variables[$key] = $value;
            $_ENV[$key] = $value;
        }

        $this->loaded = true;
    }

    public static function load(string $path): void
    {
        self::getInstance()->loadVars($path);
    }

    public function getVar(string $key, mixed $default = null): mixed
    {
        if (isset($this->variables[$key])) {
            return $this->castValue($this->variables[$key]);
        }
        if (isset($_ENV[$key])) {
            return $this->castValue($_ENV[$key]);
        }
        $value = getenv($key);
        if ($value !== false) {
            return $this->castValue($value);
        }
        return $default;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::getInstance()->getVar($key, $default);
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->getVar($key);
        return $value !== null ? (string) $value : $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        return self::getInstance()->getString($key, $default);
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->getVar($key);
        return $value !== null ? (int) $value : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        return self::getInstance()->getInt($key, $default);
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

    public static function bool(string $key, bool $default = false): bool
    {
        return self::getInstance()->getBool($key, $default);
    }

    public function getArray(string $key, array $default = []): array
    {
        $value = $this->getVar($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return array_map('trim', explode(',', (string) $value));
    }

    public static function array(string $key, array $default = []): array
    {
        return self::getInstance()->getArray($key, $default);
    }

    public function hasVar(string $key): bool
    {
        return $this->getVar($key) !== null;
    }

    public static function has(string $key): bool
    {
        return self::getInstance()->hasVar($key);
    }

    public function requireVar(string $key): mixed
    {
        $value = $this->getVar($key);
        if ($value === null) {
            throw new \RuntimeException("Required environment variable '{$key}' is not set");
        }
        return $value;
    }

    public static function require(string $key): mixed
    {
        return self::getInstance()->requireVar($key);
    }

    private function castValue(string $value): mixed
    {
        $lower = strtolower($value);
        if ($lower === 'true') return true;
        if ($lower === 'false') return false;
        if ($lower === 'null' || $lower === '') return null;
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        return $value;
    }

    public function isLoadedVar(): bool
    {
        return $this->loaded;
    }

    public static function isLoaded(): bool
    {
        return self::getInstance()->isLoadedVar();
    }

    public function resetVars(): void
    {
        $this->variables = [];
        $this->loaded = false;
    }

    public static function clear(): void
    {
        self::getInstance()->resetVars();
    }
}

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}
