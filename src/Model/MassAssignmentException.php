<?php

declare(strict_types=1);

namespace Fw\Model;

use Fw\Core\PrescriptiveException;
use RuntimeException;

class MassAssignmentException extends RuntimeException implements PrescriptiveException
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

    public function getFixCommand(): string
    {
        $shortClass = basename(str_replace('\\', '/', $this->model));
        return "php fw add:field {$shortClass} {$this->attributes[0]} string";
    }

    private function buildMessage(): string
    {
        $attrs = implode(', ', $this->attributes);
        $shortClass = basename(str_replace('\\', '/', $this->model));

        return "Mass assignment violation on {$shortClass}: [{$attrs}] are not fillable.\n"
            . "Fix: Add to \$fillable in app/Models/{$shortClass}.php, or run:\n"
            . "  {$this->getFixCommand()}";
    }
}
