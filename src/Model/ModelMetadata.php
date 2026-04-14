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

    /** @var list<string> Non-static public property names — used by hydrate() to avoid per-row ReflectionClass */
    public readonly array $publicPropertyNames;

    /**
     * @param class-string<Model> $class
     * @param array<string> $fillable
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
        public readonly array $casts,
    ) {
        // Inspect public properties once and derive both propertyTypes and publicPropertyNames
        [$this->propertyTypes, $this->publicPropertyNames] = $this->inspectPublicProperties();

        // Merge explicit casts with auto-detected types
        $this->allCasts = $this->mergeCasts();
    }

    /**
     * Check if an attribute is fillable.
     *
     * Only attributes explicitly listed in $fillable are mass-assignable.
     */
    public function isFillable(string $key): bool
    {
        $key = Str::snake($key);

        return in_array($key, $this->fillable, true);
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
     * Inspect public non-static properties once, returning both:
     *   - propertyTypes:       snake_case name → type string (for casting)
     *   - publicPropertyNames: camelCase names (for hydrate())
     *
     * Single ReflectionClass call avoids duplicate reflection per model class.
     *
     * @return array{array<string, string|null>, list<string>}
     */
    private function inspectPublicProperties(): array
    {
        $types = [];
        $names = [];
        $reflection = new ReflectionClass($this->class);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $rawName = $property->getName();
            $snakeName = Str::snake($rawName);
            $type = $property->getType();

            if ($type instanceof ReflectionNamedType) {
                $types[$snakeName] = $type->getName();
            }

            $names[] = $rawName;
        }

        return [$types, $names];
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
