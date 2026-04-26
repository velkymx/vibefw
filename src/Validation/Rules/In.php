<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use Attribute;
use Fw\Validation\Rule;

/**
 * Field must be one of the specified values.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class In implements Rule
{
    /** @var array<mixed> */
    public readonly array $values;

    public function __construct(
        array $values,
        public readonly string $message = 'The :field must be one of: :values.',
    ) {
        $this->values = $values;
    }

    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        if ($value === null || $value === '') {
            return null; // Use Required for mandatory fields
        }

        if (!in_array($value, $this->values, true)) {
            return str_replace(
                [':field', ':values'],
                [$field, implode(', ', $this->values)],
                $this->message
            );
        }

        return null;
    }

    public function __toString(): string
    {
        return 'in:' . implode(',', $this->values);
    }
}
