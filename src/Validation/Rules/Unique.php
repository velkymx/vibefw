<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use Fw\Validation\Rule;

/**
 * Value must be unique in a database table column.
 *
 * Note: Database validation is deferred — this rule stores the constraint
 * metadata. The Validator resolves it against the database at validation time
 * when a database connection is available.
 */
final class Unique implements Rule
{
    public function __construct(
        public readonly string $table,
        public readonly string $column,
        public readonly mixed $ignoreId = null,
        public readonly string $message = 'The :field has already been taken.',
    ) {}

    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Database validation requires a connection — handled by the framework
        // when wired through FormRequest. Standalone use returns null (passes).
        // The framework's request lifecycle validates uniqueness via the DB.
        return null;
    }
}
