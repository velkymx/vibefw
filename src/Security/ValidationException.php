<?php

declare(strict_types=1);

namespace Fw\Security;

use RuntimeException;

/**
 * Exception thrown when validation fails.
 *
 * PHP 8.5 enhanced: Works with FILTER_FLAG_THROW_ON_FAILURE pattern.
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, array<string>> $errors Validation errors keyed by field
     */
    public function __construct(
        public readonly array $errors,
        string $message = 'Validation failed',
    ) {
        parent::__construct($message, 422);
    }

    /**
     * Get all error messages for a specific field.
     *
     * @return array<string>
     */
    public function errorsFor(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * Get the first error message for a field.
     */
    public function firstError(?string $field = null): ?string
    {
        if ($field !== null) {
            return $this->errors[$field][0] ?? null;
        }

        // Get first field's errors, then first error message
        $firstFieldErrors = reset($this->errors);
        return $firstFieldErrors !== false ? ($firstFieldErrors[0] ?? null) : null;
    }

    /**
     * Get all error messages as a flat array.
     *
     * @return array<string>
     */
    public function allErrors(): array
    {
        $all = [];
        foreach ($this->errors as $fieldErrors) {
            $all = array_merge($all, $fieldErrors);
        }
        return $all;
    }

    /**
     * Check if a specific field has errors.
     */
    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]) && !empty($this->errors[$field]);
    }

    /**
     * Get all field names that have errors.
     *
     * @return array<string>
     */
    public function failedFields(): array
    {
        return array_keys($this->errors);
    }
}
