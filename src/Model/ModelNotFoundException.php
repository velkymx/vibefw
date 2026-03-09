<?php

declare(strict_types=1);

namespace Fw\Model;

use ReflectionClass;
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
        $className = new ReflectionClass($model)->getShortName();

        parent::__construct(
            $message ?: ($id !== null
                ? "{$className} with ID [{$id}] not found"
                : "{$className} not found"),
            404
        );
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
