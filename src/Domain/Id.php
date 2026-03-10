<?php

declare(strict_types=1);

namespace Fw\Domain;

use Fw\Support\Str;
use InvalidArgumentException;

/**
 * Base class for typed entity IDs.
 *
 * Extend this class to create typed IDs for different entities:
 *
 *     final readonly class OrderId extends Id {}
 *     final readonly class ProductId extends Id {}
 *
 * This prevents accidentally passing a UserId where an OrderId is expected.
 */
abstract readonly class Id implements ValueObject
{
    public string $value;

    final protected function __construct(string $id)
    {
        $this->value = $id;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Generate a new ID (UUID v4).
     */
    public static function generate(): static
    {
        return new static(Str::uuid());
    }

    /**
     * Generate a new ID (ULID - sortable).
     */
    public static function generateUlid(): static
    {
        return new static(Str::ulid());
    }

    /**
     * Create from an existing string, with validation.
     *
     * @throws InvalidArgumentException If format is invalid
     */
    public static function from(string $id): static
    {
        // Accept both UUID and ULID formats
        if (!Str::isUuid($id) && !Str::isUlid($id)) {
            throw new InvalidArgumentException(
                sprintf('Invalid %s format: %s', static::class, $id)
            );
        }

        return new static(Str::isUuid($id) ? strtolower($id) : strtoupper($id));
    }

    /**
     * Create from trusted source without validation.
     */
    public static function fromTrusted(string $id): static
    {
        return new static($id);
    }

    /**
     * Wrap a value - returns as-is if already correct type, otherwise creates new.
     *
     * Used by Model auto-casting.
     */
    public static function wrap(string|self $value): static
    {
        if ($value instanceof static) {
            return $value;
        }

        return static::fromTrusted($value);
    }

    /**
     * Check if a string is a valid ID format.
     */
    public static function isValid(string $id): bool
    {
        return Str::isUuid($id) || Str::isUlid($id);
    }

    public function equals(ValueObject $other): bool
    {
        return $other instanceof static && $this->value === $other->value;
    }
}
