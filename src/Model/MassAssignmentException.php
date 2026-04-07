<?php

declare(strict_types=1);

namespace Fw\Model;

use RuntimeException;

/**
 * Exception thrown when attempting to mass-assign guarded attributes.
 *
 * This helps prevent mass assignment vulnerabilities where attackers
 * could modify fields like 'is_admin' or 'role' through request data.
 */
class MassAssignmentException extends RuntimeException
{
    /**
     * The model class that rejected the assignment.
     */
    public readonly string $model;

    /**
     * The attributes that were rejected.
     * @var array<string>
     */
    public readonly array $attributes;

    /**
     * @param array<string> $attributes
     */
    public function __construct(string $model, array $attributes, string $message = '')
    {
        $this->model = $model;
        $this->attributes = $attributes;

        parent::__construct($message ?: $this->buildMessage());
    }

    /**
     * Create exception for specific attributes.
     *
     * @param class-string $model
     * @param array<string> $attributes
     */
    public static function forAttributes(string $model, array $attributes): self
    {
        return new self($model, $attributes);
    }

    /**
     * Build a helpful error message.
     */
    private function buildMessage(): string
    {
        $attrs = implode(', ', $this->attributes);
        $shortClass = basename(str_replace('\\', '/', $this->model));
        $attrList = implode(' ', $this->attributes);

        return "Mass assignment violation on {$shortClass}: [{$attrs}] are not fillable.\n"
            . "Fix: Add to \$fillable in app/Models/{$shortClass}.php, or run:\n"
            . "  php fw add:field {$shortClass} {$this->attributes[0]} string";
    }
}
