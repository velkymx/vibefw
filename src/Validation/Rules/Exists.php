<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use Fw\Database\Connection;
use Fw\Validation\DatabaseRule;

/**
 * Value must exist in a database table column.
 *
 * The Validator resolves this rule via resolveWithDb() when a database
 * connection is available. Calling validate() directly always passes (no DB).
 */
final class Exists implements DatabaseRule
{
    public function __construct(
        public readonly string $table,
        public readonly string $column,
        public readonly string $message = 'The selected :field is invalid.',
    ) {}

    /**
     * Stateless pass — DB check runs through resolveWithDb().
     */
    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        return null;
    }

    /**
     * Check that $value exists in $this->table.$this->column.
     */
    public function resolveWithDb(Connection $connection, mixed $value, string $field, array $data): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $exists = $connection->table($this->table)->where($this->column, $value)->exists();

        return $exists ? null : str_replace(':field', $field, $this->message);
    }

    public function __toString(): string
    {
        return 'exists:' . $this->table . ',' . $this->column;
    }
}
