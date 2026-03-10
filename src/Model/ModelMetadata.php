<?php

declare(strict_types=1);

namespace Fw\Model;

use Fw\Support\Str;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Cached metadata for a Model class.
 *
 * Stores reflection-derived information to avoid repeated reflection calls.
 */
final class ModelMetadata
{
    /** @var array<string, string|null> Property types from reflection */
    public readonly array $propertyTypes;

    /** @var array<string, string|null> Merged casts (explicit + auto-detected) */
    public readonly array $allCasts;

    /**
     * @param class-string<Model> $class
     * @param array<string> $fillable
     * @param array<string> $guarded
     * @param array<string, string> $casts
     */
    public function __construct(
        public readonly string $class,
        public readonly string $table,
        public readonly string $primaryKey,
        public readonly bool $incrementing,
        public readonly string $keyType,
        public readonly bool $timestamps,
        public readonly string $createdAtColumn,
        public readonly string $updatedAtColumn,
        public readonly array $fillable,
        public readonly array $guarded,
        public readonly array $casts,
    ) {
        // Auto-detect property types via reflection
        $this->propertyTypes = $this->detectPropertyTypes();

        // Merge explicit casts with auto-detected types
        $this->allCasts = $this->mergeCasts();
    }

    /**
     * Check if an attribute is fillable.
     */
    public function isFillable(string $key): bool
    {
        $key = Str::snake($key);

        // If fillable is defined and non-empty, key must be in it
        if (!empty($this->fillable)) {
            return in_array($key, $this->fillable, true);
        }

        // Otherwise, check if it's not guarded
        return !in_array($key, $this->guarded, true);
    }

    /**
     * Get the cast type for an attribute.
     */
    public function getCastType(string $key): ?string
    {
        $key = Str::snake($key);
        return $this->allCasts[$key] ?? null;
    }

    /**
     * Get all attribute names (from properties and casts).
     *
     * @return array<string>
     */
    public function getAttributeNames(): array
    {
        return array_unique(array_merge(
            array_keys($this->propertyTypes),
            array_keys($this->casts)
        ));
    }

    /**
     * Detect property types from class reflection.
     *
     * @return array<string, string|null>
     */
    private function detectPropertyTypes(): array
    {
        $types = [];
        $reflection = new ReflectionClass($this->class);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            // Skip static properties
            if ($property->isStatic()) {
                continue;
            }

            $name = Str::snake($property->getName());
            $type = $property->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $types[$name] = $type->getName();
            } elseif ($type instanceof ReflectionNamedType) {
                $types[$name] = $type->getName();
            }
        }

        return $types;
    }

    /**
     * Merge explicit casts with auto-detected property types.
     *
     * @return array<string, string|null>
     */
    private function mergeCasts(): array
    {
        $merged = $this->casts;

        foreach ($this->propertyTypes as $key => $type) {
            if (!isset($merged[$key]) && $type !== null) {
                // Only add non-built-in types (Value Objects)
                if (!in_array($type, ['int', 'float', 'string', 'bool', 'array', 'mixed'], true)) {
                    $merged[$key] = $type;
                }
            }
        }

        return $merged;
    }
}
