<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use Attribute;
use Fw\Validation\Rule;

/**
 * Field must be present and not empty.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Required implements Rule
{
    public function __construct(
        public readonly string $message = 'The :field field is required.',
    ) {}

    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return str_replace(':field', $field, $this->message);
        }

        return null;
    }

    public function __toString(): string
    {
        return 'required';
    }
}
