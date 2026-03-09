<?php

declare(strict_types=1);

namespace Fw\Domain;

use InvalidArgumentException;

/**
 * Email Value Object.
 *
 * Encapsulates email validation and normalization.
 * Uses PHP 8.4 property hooks for derived properties.
 */
final readonly class Email implements ValueObject
{
    public string $value;

    private function __construct(string $email)
    {
        $this->value = $email;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Create an Email from a string, validating the format.
     *
     * @throws InvalidArgumentException If email format is invalid
     */
    public static function from(string $email): self
    {
        $normalized = mb_strtolower(trim($email));

        if (!filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email format: {$email}");
        }

        return new self($normalized);
    }

    /**
     * Create an Email without validation (for trusted sources like DB).
     */
    public static function fromTrusted(string $email): self
    {
        return new self($email);
    }

    /**
     * Wrap a value - returns as-is if already Email, otherwise creates new.
     *
     * Used by Model auto-casting.
     */
    public static function wrap(string|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return self::fromTrusted($value);
    }

    /**
     * Get the local part (before @).
     */
    public function localPart(): string
    {
        return explode('@', $this->value)[0];
    }

    /**
     * Get the domain part (after @).
     */
    public function domain(): string
    {
        return explode('@', $this->value)[1];
    }

    /**
     * Check if this is a specific domain.
     */
    public function isDomain(string $domain): bool
    {
        return strcasecmp($this->domain(), $domain) === 0;
    }

    /**
     * Check if this is a free email provider.
     */
    public function isFreeProvider(): bool
    {
        $freeProviders = [
            'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com',
            'aol.com', 'icloud.com', 'mail.com', 'protonmail.com',
        ];

        return in_array($this->domain(), $freeProviders, true);
    }

    public function equals(ValueObject $other): bool
    {
        return $other instanceof self && $this->value === $other->value;
    }
}
