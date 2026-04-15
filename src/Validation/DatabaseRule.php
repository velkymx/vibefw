<?php

declare(strict_types=1);

namespace Fw\Validation;

use Fw\Database\Connection;

/**
 * Marker interface for validation rules that require a database connection.
 *
 * Rules implementing this interface are resolved by the Validator using the
 * active database connection instead of the stateless Rule::validate() path.
 */
interface DatabaseRule extends Rule
{
    /**
     * Validate the value using a live database connection.
     *
     * @param array<string, mixed> $data All input data (for cross-field checks)
     * @return string|null Error message on failure, null on pass
     */
    public function resolveWithDb(Connection $connection, mixed $value, string $field, array $data): ?string;
}
