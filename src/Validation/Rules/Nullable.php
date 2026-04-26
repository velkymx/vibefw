<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use Attribute;
use Fw\Validation\Rule;

/**
 * Mark a field as nullable (allows null values).
 *
 * When combined with other rules, those rules will only be applied
 * if the value is not null.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Nullable implements Rule
{
    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        // Always passes - this is a marker attribute
        // Other validators skip null values by default
        return null;
    }

    public function __toString(): string
    {
        return 'nullable';
    }
}
