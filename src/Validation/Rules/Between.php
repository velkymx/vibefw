<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use Attribute;
use Fw\Validation\Rule;

/**
 * Field must be between min and max values.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Between implements Rule
{
    public function __construct(
        public readonly int|float $min,
        public readonly int|float $max,
        public readonly string $message = 'The :field must be between :min and :max.',
    ) {}

    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        if ($value === null || $value === '') {
            return null; // Use Required for mandatory fields
        }

        $error = str_replace(
            [':field', ':min', ':max'],
            [$field, (string) $this->min, (string) $this->max],
            $this->message
        );

        if (is_string($value)) {
            $length = mb_strlen($value);
            if ($length < $this->min || $length > $this->max) {
                return str_replace(
                    [':min', ':max'],
                    [$this->min . ' characters', $this->max . ' characters'],
                    $error
                );
            }
        } elseif (is_numeric($value)) {
            $num = (float) $value;
            if ($num < $this->min || $num > $this->max) {
                return $error;
            }
        } elseif (is_array($value)) {
            $count = count($value);
            if ($count < $this->min || $count > $this->max) {
                return str_replace(
                    [':min', ':max'],
                    [$this->min . ' items', $this->max . ' items'],
                    $error
                );
            }
        }

        return null;
    }

    public function __toString(): string
    {
        return 'between:' . $this->min . ',' . $this->max;
    }
}
