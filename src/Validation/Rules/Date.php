<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use Attribute;
use DateTimeImmutable;
use Fw\Validation\Rule;

/**
 * Field must be a valid date.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Date implements Rule
{
    public function __construct(
        public readonly ?string $format = null,
        public readonly string $message = 'The :field must be a valid date.',
    ) {}

    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        if ($value === null || $value === '') {
            return null; // Use Required for mandatory fields
        }

        if (!is_string($value)) {
            return str_replace(':field', $field, $this->message);
        }

        if ($this->format !== null) {
            $date = DateTimeImmutable::createFromFormat($this->format, $value);
            if ($date === false || $date->format($this->format) !== $value) {
                return str_replace(':field', $field, $this->message);
            }
        } else {
            if (strtotime($value) === false) {
                return str_replace(':field', $field, $this->message);
            }
        }

        return null;
    }
}
