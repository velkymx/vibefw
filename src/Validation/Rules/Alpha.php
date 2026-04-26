<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use Attribute;
use Fw\Validation\Rule;

/**
 * Field must contain only alphabetic characters.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Alpha implements Rule
{
    public function __construct(
        public readonly string $message = 'The :field must only contain letters.',
    ) {}

    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || !ctype_alpha($value)) {
            return str_replace(':field', $field, $this->message);
        }

        return null;
    }

    public function __toString(): string
    {
        return 'alpha';
    }
}
