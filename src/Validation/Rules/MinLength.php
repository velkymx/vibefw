<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use Fw\Validation\Rule;

/**
 * String must have at least N characters.
 */
final class MinLength implements Rule
{
    public function __construct(
        public readonly int $length,
        public readonly string $message = 'The :field must be at least :min characters.',
    ) {}

    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (mb_strlen((string) $value) < $this->length) {
            return str_replace([':field', ':min'], [$field, (string) $this->length], $this->message);
        }

        return null;
    }

    public function __toString(): string
    {
        return 'min:' . $this->length;
    }
}
