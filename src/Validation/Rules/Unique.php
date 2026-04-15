<?php

declare(strict_types=1);

namespace Fw\Validation\Rules;

use Fw\Database\Connection;
use Fw\Validation\DatabaseRule;

/**
 * Value must be unique in a database table column.
 *
 * The Validator resolves this rule via resolveWithDb() when a database
 * connection is available. Calling validate() directly always passes (no DB).
 */
final class Unique implements DatabaseRule
{
    public function __construct(
        public readonly string $table,
        public readonly string $column,
        public readonly mixed $ignoreId = null,
        public readonly string $message = 'The :field has already been taken.',
    ) {}

    /**
     * Stateless pass — DB check runs through resolveWithDb().
     */
    public function validate(mixed $value, string $field, array $data = []): ?string
    {
        return null;
    }

    /**
     * Check that $value is unique in $this->table.$this->column.
     * Optionally ignores the row with id = $this->ignoreId (for update flows).
     */
    public function resolveWithDb(Connection $connection, mixed $value, string $field, array $data): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $query = $connection->table($this->table)->where($this->column, $value);

        if ($this->ignoreId !== null) {
            $query = $query->where('id', '!=', $this->ignoreId);
        }

        return $query->exists() ? str_replace(':field', $field, $this->message) : null;
    }
}
