<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use Attribute;
use Fw\Validation\Rule;

/**
 * Field must be numeric.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Numeric implements Rule
{
    public function __construct(
        public readonly string $message = 'The :field must be a number.',
    ) {}

    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        if ($value === null || $value === '') {
            return null; // Use Required for mandatory fields
        }

        if (!is_numeric($value)) {
            return str_replace(':field', $field, $this->message);
        }

        return null;
    }

    public function __toString(): string
    {
        return 'numeric';
    }
}
