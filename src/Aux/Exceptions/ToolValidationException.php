<?php

declare(strict_types=1);

namespace Fw\Aux\Exceptions;

use InvalidArgumentException;

final class ToolValidationException extends InvalidArgumentException
{
    /**
     * @param array<string, list<string>> $errors Field-specific errors
     */
    public function __construct(string $message, public array $errors = [])
    {
        parent::__construct($message);
    }

    /**
     * @param array<string, list<string>> $errors
     */
    public static function fromValidationErrors(string $toolName, array $errors): self
    {
        $messages = [];

        foreach ($errors as $field => $fieldErrors) {
            $messages[] = "{$field}: " . implode(', ', $fieldErrors);
        }

        return new self(
            "Tool '{$toolName}' validation failed: " . implode('; ', $messages),
            $errors,
        );
    }
}
