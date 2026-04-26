<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use Fw\Validation\Rule;

/**
 * String must have at most N characters.
 */
final class MaxLength implements Rule
{
    public function __construct(
        public readonly int $length,
        public readonly string $message = 'The :field must not exceed :max characters.',
    ) {}

    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (mb_strlen((string) $value) > $this->length) {
            return str_replace([':field', ':max'], [$field, (string) $this->length], $this->message);
        }

        return null;
    }

    public function __toString(): string
    {
        return 'max:' . $this->length;
    }
}
