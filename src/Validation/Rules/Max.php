<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use Attribute;
use Fw\Validation\Rule;

/**
 * Field must have a maximum length (strings) or value (numbers).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Max implements Rule
{
    public function __construct(
        public readonly int|float $value,
        public readonly string $message = 'The :field must not exceed :max.',
    ) {}

    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        if ($value === null || $value === '') {
            return null; // Use Required for mandatory fields
        }

        $error = str_replace([':field', ':max'], [$field, (string) $this->value], $this->message);

        if (is_string($value)) {
            if (mb_strlen($value) > $this->value) {
                return str_replace(':max', $this->value . ' characters', $error);
            }
        } elseif (is_numeric($value)) {
            if ((float) $value > $this->value) {
                return $error;
            }
        } elseif (is_array($value)) {
            if (count($value) > $this->value) {
                return str_replace(':max', $this->value . ' items', $error);
            }
        }

        return null;
    }

    public function __toString(): string
    {
        return 'max:' . $this->value;
    }
}
