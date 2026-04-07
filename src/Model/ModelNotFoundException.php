<?php

declare(strict_types=1);

namespace Fw\Model;

use RuntimeException;

/**
 * Exception thrown when a model is not found.
 */
final class ModelNotFoundException extends RuntimeException
{
    /**
     * @param class-string<Model> $model
     */
    public function __construct(
        public readonly string $model,
        public readonly mixed $id = null,
        string $message = '',
    ) {
        parent::__construct($message ?: $this->buildMessage(), 404);
    }

    private function buildMessage(): string
    {
        $shortClass = basename(str_replace('\\', '/', $this->model));

        $msg = $this->id !== null
            ? "{$shortClass} with ID [{$this->id}] not found"
            : "{$shortClass} not found";

        return $msg . "\nFix: Check your ID or query conditions. Inspect with:\n"
            . "  php fw model:inspect {$shortClass}";
    }

    /**
     * Create for a specific model and ID.
     *
     * @param class-string<Model> $model
     */
    public static function forModel(string $model, mixed $id = null): self
    {
        return new self($model, $id);
    }
}
