<?php

declare(strict_types=1);

namespace Fw\Model;

use Fw\Core\PrescriptiveException;
use RuntimeException;

final class ModelNotFoundException extends RuntimeException implements PrescriptiveException
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

    /**
     * @param class-string<Model> $model
     */
    public static function forModel(string $model, mixed $id = null): self
    {
        return new self($model, $id);
    }

    public function getFixCommand(): string
    {
        $shortClass = basename(str_replace('\\', '/', $this->model));
        return "php fw model:inspect {$shortClass}";
    }

    private function buildMessage(): string
    {
        $shortClass = basename(str_replace('\\', '/', $this->model));

        $msg = $this->id !== null
            ? "{$shortClass} with ID [{$this->id}] not found"
            : "{$shortClass} not found";

        return $msg . "\nFix: Check your ID or query conditions. Inspect with:\n"
            . "  {$this->getFixCommand()}";
    }
}
