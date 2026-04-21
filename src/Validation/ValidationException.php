<?php

declare(strict_types=1);

namespace Fw\Validation;

use RuntimeException;

/**
 * Exception thrown when validation fails.
 *
 * Contains all validation errors for easy access and display.
 */
class ValidationException extends RuntimeException
{
    /**
     * @param array<string, array<string>> $errors Field => error messages
     */
    public function __construct(
        public readonly array $errors,
        string $message = '',
    ) {
        parent::__construct($message ?: $this->buildMessage());
    }

    /**
     * Get errors for a specific field.
     *
     * @return array<string>
     */
    public function errorsFor(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * Get the first error message for each field.
     *
     * @return array<string, string>
     */
    public function firstErrors(): array
    {
        $first = [];
        foreach ($this->errors as $field => $messages) {
            if (!empty($messages)) {
                $first[$field] = $messages[0];
            }
        }
        return $first;
    }

    /**
     * Get all error messages as a flat array.
     *
     * @return array<string>
     */
    public function allMessages(): array
    {
        $messages = [];
        foreach ($this->errors as $fieldErrors) {
            foreach ($fieldErrors as $error) {
                $messages[] = $error;
            }
        }
        return $messages;
    }

    /**
     * Check if a specific field has errors.
     */
    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]) && !empty($this->errors[$field]);
    }

    /**
     * Get JSON representation of errors.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'errors' => $this->errors,
        ];
    }

    private function buildMessage(): string
    {
        $fields = implode(', ', array_keys($this->errors));

        return "The given data was invalid. Failing fields: {$fields}\n"
            . "Fix: Check your FormRequest rules or run:\n"
            . "  php fw check";
    }
}
