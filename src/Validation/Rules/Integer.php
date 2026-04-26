<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use Attribute;
use Fw\Validation\Rule;

/**
 * Field must be an integer.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Integer implements Rule
{
    public function __construct(
        public readonly string $message = 'The :field must be an integer.',
    ) {}

    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        if ($value === null || $value === '') {
            return null; // Use Required for mandatory fields
        }

        if (!is_int($value) && !ctype_digit((string) $value)) {
            return str_replace(':field', $field, $this->message);
        }

        return null;
    }

    public function __toString(): string
    {
        return 'integer';
    }
}
