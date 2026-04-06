<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use Fw\Validation\Rule;

/**
 * Value must exist in a database table column.
 *
 * Note: Database validation is deferred — this rule stores the constraint
 * metadata. The Validator resolves it against the database at validation time
 * when a database connection is available.
 */
final class Exists implements Rule
{
    public function __construct(
        public readonly string $table,
        public readonly string $column,
        public readonly string $message = 'The selected :field is invalid.',
    ) {}

    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Database validation requires a connection — handled by the framework
        // when wired through FormRequest. Standalone use returns null (passes).
        return null;
    }
}
