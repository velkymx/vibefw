<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use Attribute;
use Fw\Validation\Rule;

/**
 * Field must match a confirmation field (e.g., password and password_confirmation).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Confirmed implements Rule
{
    public function __construct(
        public readonly ?string $confirmationField = null,
        public readonly string $message = 'The :field confirmation does not match.',
    ) {}

    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        if ($value === null || $value === '') {
            return null; // Use Required for mandatory fields
        }

        $confirmationField = $this->confirmationField ?? $field . '_confirmation';
        $confirmationValue = $data[$confirmationField] ?? null;

        if ($value !== $confirmationValue) {
            return str_replace(':field', $field, $this->message);
        }

        return null;
    }

    public function __toString(): string
    {
        return 'confirmed';
    }
}
