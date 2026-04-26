<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use Attribute;
use Fw\Validation\Rule;

/**
 * Field must be a valid UUID.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Uuid implements Rule
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        public readonly string $message = 'The :field must be a valid UUID.',
    ) {}

    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        if ($value === null || $value === '') {
            return null; // Use Required for mandatory fields
        }

        if (!is_string($value) || !preg_match(self::UUID_PATTERN, $value)) {
            return str_replace(':field', $field, $this->message);
        }

        return null;
    }

    public function __toString(): string
    {
        return 'uuid';
    }
}
