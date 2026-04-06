<?php

declare(strict_types=1);

namespace Fw\Config;

use Fw\Support\Result;

/**
 * Validates configuration arrays against schemas.
 *
 * Prevents common config mistakes:
 * - Missing required keys
 * - Wrong types
 * - Invalid values
 * - Typos in key names
 */
final class ConfigValidator
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $schemas = [];

    /**
     * Register a configuration schema.
     *
     * @param string $name Config name (e.g., 'database', 'app')
     * @param array<string, mixed> $schema Schema definition
     */
    public static function registerSchema(string $name, array $schema): void
    {
        self::$schemas[$name] = $schema;
    }

    /**
     * Validate a configuration array against its schema.
     *
     * @param string $name Config name
     * @param array<string, mixed> $config Configuration to validate
     * @return Result<true, array<string>> Ok(true) or Err([errors])
     */
    public static function validate(string $name, array $config): Result
    {
        if (!isset(self::$schemas[$name])) {
            return Result::ok(true);
        }

        $schema = self::$schemas[$name];
        $errors = [];

        // Check for required keys
        foreach ($schema as $key => $rules) {
            if (($rules['required'] ?? false) && !array_key_exists($key, $config)) {
                $errors[] = "Missing required config key: $name.$key";
            }
        }

        // Validate provided keys
        foreach ($config as $key => $value) {
            if (!isset($schema[$key])) {
                // Check for similar keys (typo detection)
                $similar = self::findSimilarKeys($key, array_keys($schema));
                if ($similar !== null) {
                    $errors[] = "Unknown config key '$name.$key'. Did you mean '$similar'?";
                } else {
                    $errors[] = "Unknown config key: $name.$key";
                }
                continue;
            }

            $rules = $schema[$key];

            // Type validation
            if (isset($rules['type'])) {
                $typeError = self::validateType($value, $rules['type'], "$name.$key");
                if ($typeError !== null) {
                    $errors[] = $typeError;
                }
            }

            // Enum validation
            if (isset($rules['enum']) && !in_array($value, $rules['enum'], true)) {
                $allowed = implode(', ', array_map(fn ($v) => var_export($v, true), $rules['enum']));
                $errors[] = "Invalid value for $name.$key. Allowed: $allowed";
            }

            // Min/max validation for numbers
            if (isset($rules['min']) && is_numeric($value) && $value < $rules['min']) {
                $errors[] = "$name.$key must be at least {$rules['min']}";
            }
            if (isset($rules['max']) && is_numeric($value) && $value > $rules['max']) {
                $errors[] = "$name.$key must be at most {$rules['max']}";
            }

            // Pattern validation for strings
            if (isset($rules['pattern']) && is_string($value)) {
                if (!preg_match($rules['pattern'], $value)) {
                    $errors[] = "$name.$key does not match required pattern";
                }
            }
        }

        if (!empty($errors)) {
            return Result::err($errors);
        }

        return Result::ok(true);
    }

    /**
     * Validate all registered configs.
     *
     * @param array<string, array<string, mixed>> $configs Map of config name => config array
     * @return Result<true, array<string>>
     */
    public static function validateAll(array $configs): Result
    {
        $allErrors = [];

        foreach ($configs as $name => $config) {
            self::validate($name, $config)->match(
                ok: fn() => null,
                err: fn(array $errors) => $allErrors = array_merge($allErrors, $errors),
            );
        }

        if (!empty($allErrors)) {
            return Result::err($allErrors);
        }

        return Result::ok(true);
    }

    /**
     * Get the default schemas for framework configs.
     */
    public static function registerDefaultSchemas(): void
    {
        // Database config schema
        self::registerSchema('database', [
            'enabled' => ['type' => 'bool', 'required' => false],
            'driver' => ['type' => 'string', 'required' => true, 'enum' => ['sqlite', 'mysql', 'pgsql']],
            'host' => ['type' => 'string', 'required' => false],
            'port' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 65535],
            'database' => ['type' => 'string', 'required' => true],
            'username' => ['type' => 'string', 'required' => false],
            'password' => ['type' => 'string', 'required' => false],
            'charset' => ['type' => 'string', 'required' => false],
            'logging' => ['type' => 'bool', 'required' => false],
            'persistent' => ['type' => 'bool', 'required' => false],
        ]);

        // App config schema
        self::registerSchema('app', [
            'name' => ['type' => 'string', 'required' => true],
            'env' => ['type' => 'string', 'required' => true, 'enum' => ['local', 'development', 'staging', 'production']],
            'debug' => ['type' => 'bool', 'required' => false],
            'url' => ['type' => 'string', 'required' => false],
            'timezone' => ['type' => 'string', 'required' => false],
            'locale' => ['type' => 'string', 'required' => false],
            'secure_cookies' => ['type' => 'bool', 'required' => false],
        ]);

        // Cache config schema
        self::registerSchema('cache', [
            'driver' => ['type' => 'string', 'required' => true, 'enum' => ['file', 'apcu', 'redis', 'array']],
            'prefix' => ['type' => 'string', 'required' => false],
            'ttl' => ['type' => 'int', 'required' => false, 'min' => 0],
        ]);

        // Queue config schema
        self::registerSchema('queue', [
            'default' => ['type' => 'string', 'required' => false],
            'driver' => ['type' => 'string', 'required' => false, 'enum' => ['sync', 'file', 'database', 'redis']],
            'path' => ['type' => 'string', 'required' => false],
            'table' => ['type' => 'string', 'required' => false],
            'retry_after' => ['type' => 'int', 'required' => false, 'min' => 0],
            'max_tries' => ['type' => 'int', 'required' => false, 'min' => 1],
        ]);
    }

    /**
     * Validate a value's type.
     */
    private static function validateType(mixed $value, string $type, string $path): ?string
    {
        $actualType = gettype($value);

        $valid = match ($type) {
            'string' => is_string($value),
            'int', 'integer' => is_int($value),
            'float', 'double' => is_float($value) || is_int($value),
            'bool', 'boolean' => is_bool($value),
            'array' => is_array($value),
            'null' => is_null($value),
            'callable' => is_callable($value),
            default => true, // Unknown type, skip validation
        };

        if (!$valid) {
            return "$path must be of type $type, got $actualType";
        }

        return null;
    }

    /**
     * Find similar key names for typo detection.
     *
     * @param array<string> $validKeys
     */
    private static function findSimilarKeys(string $input, array $validKeys): ?string
    {
        $minDistance = PHP_INT_MAX;
        $closest = null;

        foreach ($validKeys as $key) {
            $distance = levenshtein($input, $key);
            if ($distance < $minDistance && $distance <= 2) {
                $minDistance = $distance;
                $closest = $key;
            }
        }

        return $closest;
    }
}
