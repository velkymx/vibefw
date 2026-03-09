<?php

declare(strict_types=1);

namespace Fw\Domain;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Money Value Object.
 *
 * Stores amounts in cents to avoid floating-point precision issues.
 * Uses PHP 8.4 property hooks for derived values.
 *
 * @property-read float $dollars The amount in dollars (derived from cents)
 * @property-read string $formatted The formatted currency string
 */
final readonly class Money implements ValueObject, JsonSerializable
{
    /**
     * @param int $cents Amount in smallest currency unit (cents)
     * @param string $currency ISO 4217 currency code
     */
    public function __construct(
        public int $cents,
        public string $currency = 'USD'
    ) {
        if ($currency === '') {
            throw new InvalidArgumentException('Currency cannot be empty');
        }
    }

    public function __toString(): string
    {
        return $this->formatted();
    }

    /**
     * Create Money from dollar amount.
     */
    public static function dollars(float|int $amount, string $currency = 'USD'): self
    {
        return new self((int) round($amount * 100), $currency);
    }

    /**
     * Create Money from cents.
     */
    public static function cents(int $amount, string $currency = 'USD'): self
    {
        return new self($amount, $currency);
    }

    /**
     * Create zero money.
     */
    public static function zero(string $currency = 'USD'): self
    {
        return new self(0, $currency);
    }

    /**
     * Get amount in dollars (derived property).
     */
    public function toDollars(): float
    {
        return $this->cents / 100;
    }

    /**
     * Get formatted string.
     */
    public function formatted(string $locale = 'en_US'): string
    {
        $symbols = [
            'USD' => '$', 'EUR' => '€', 'GBP' => '£',
            'JPY' => '¥', 'CAD' => 'C$', 'AUD' => 'A$',
        ];

        $symbol = $symbols[$this->currency] ?? $this->currency . ' ';
        $amount = number_format($this->toDollars(), 2);

        return $symbol . $amount;
    }

    /**
     * Add another Money value.
     *
     * @throws InvalidArgumentException If currencies don't match
     */
    public function add(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->cents + $other->cents, $this->currency);
    }

    /**
     * Subtract another Money value.
     *
     * @throws InvalidArgumentException If currencies don't match
     */
    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->cents - $other->cents, $this->currency);
    }

    /**
     * Multiply by a factor.
     */
    public function multiply(float|int $factor): self
    {
        return new self((int) round($this->cents * $factor), $this->currency);
    }

    /**
     * Divide by a divisor.
     */
    public function divide(float|int $divisor): self
    {
        if ($divisor === 0) {
            throw new InvalidArgumentException('Cannot divide by zero');
        }

        return new self((int) round($this->cents / $divisor), $this->currency);
    }

    /**
     * Get absolute value.
     */
    public function absolute(): self
    {
        return new self(abs($this->cents), $this->currency);
    }

    /**
     * Negate the amount.
     */
    public function negate(): self
    {
        return new self(-$this->cents, $this->currency);
    }

    /**
     * Check if zero.
     */
    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    /**
     * Check if positive.
     */
    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    /**
     * Check if negative.
     */
    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    /**
     * Check if greater than another amount.
     */
    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->cents > $other->cents;
    }

    /**
     * Check if greater than or equal to another amount.
     */
    public function greaterThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->cents >= $other->cents;
    }

    /**
     * Check if less than another amount.
     */
    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->cents < $other->cents;
    }

    /**
     * Check if less than or equal to another amount.
     */
    public function lessThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->cents <= $other->cents;
    }

    /**
     * Allocate money according to ratios.
     *
     * @param array<int> $ratios
     * @return array<self>
     */
    public function allocate(array $ratios): array
    {
        $total = array_sum($ratios);
        if ($total === 0) {
            throw new InvalidArgumentException('Ratios must sum to more than zero');
        }

        $remainder = $this->cents;
        $results = [];

        foreach ($ratios as $ratio) {
            $share = (int) floor($this->cents * $ratio / $total);
            $results[] = new self($share, $this->currency);
            $remainder -= $share;
        }

        // Distribute remainder to first recipients (banker's distribution)
        for ($i = 0; $remainder > 0; $i++) {
            $results[$i] = new self($results[$i]->cents + 1, $this->currency);
            $remainder--;
        }

        return $results;
    }

    /**
     * Convert to another currency.
     */
    public function convertTo(string $currency, float $exchangeRate): self
    {
        return new self(
            (int) round($this->cents * $exchangeRate),
            $currency
        );
    }

    public function equals(ValueObject $other): bool
    {
        return $other instanceof self
            && $this->cents === $other->cents
            && $this->currency === $other->currency;
    }

    public function jsonSerialize(): array
    {
        return [
            'cents' => $this->cents,
            'currency' => $this->currency,
            'formatted' => $this->formatted(),
        ];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot operate on different currencies: {$this->currency} vs {$other->currency}"
            );
        }
    }
}
