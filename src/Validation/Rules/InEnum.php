<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use BackedEnum;
use Fw\Validation\Rule;

/**
 * Value must be a valid case of a backed enum.
 */
final class InEnum implements Rule
{
    /** @var array<string|int> */
    private readonly array $validValues;

    /**
     * @param class-string<BackedEnum> $enumClass
     */
    public function __construct(
        public readonly string $enumClass,
        public readonly string $message = 'The :field must be one of: :values.',
    ) {
        $this->validValues = array_map(
            fn (BackedEnum $case) => $case->value,
            $enumClass::cases()
        );
    }

    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!in_array($value, $this->validValues, true)) {
            $values = implode(', ', $this->validValues);
            return str_replace([':field', ':values'], [$field, $values], $this->message);
        }

        return null;
    }
}
