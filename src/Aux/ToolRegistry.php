<?php

declare(strict_types=1);

namespace Fw\Aux;

use Fw\Aux\Exceptions\ToolNotFoundException;
use Fw\Aux\Exceptions\ToolValidationException;
use Fw\Support\Option;
use Fw\Support\Result;
use Throwable;

final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    public function register(Tool $tool): self
    {
        $this->tools[$tool->name] = $tool;

        return $this;
    }

    public function get(string $name): Option
    {
        return Option::fromNullable($this->tools[$name] ?? null);
    }

    /** @return array<string, Tool> */
    public function all(): array
    {
        return $this->tools;
    }

    /** @param array<string> $callerAbilities */
    public function allFor(array $callerAbilities): array
    {
        return array_filter(
            $this->tools,
            fn(Tool $tool) => $tool->isAccessibleWith($callerAbilities),
        );
    }

    /**
     * @param array<string> $callerAbilities
     */
    public function call(string $name, array $input, array $callerAbilities = []): Result
    {
        $tool = $this->tools[$name] ?? null;

        if ($tool === null) {
            return Result::err(new ToolNotFoundException($name));
        }

        if (!$tool->isAccessibleWith($callerAbilities)) {
            return Result::err(new ToolNotFoundException($name));
        }

        $validation = $this->validateInput($tool, $input);

        if ($validation->isErr()) {
            return Result::err($validation->unwrapErr());
        }

        try {
            $result = ($tool->handler)($input);

            return Result::ok($result);
        } catch (Throwable $e) {
            return Result::err($e);
        }
    }

    private function validateInput(Tool $tool, array $input): Result
    {
        $schema = $tool->inputSchema;

        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $requiredField) {
                if (!array_key_exists($requiredField, $input)) {
                    return Result::err(
                        new ToolValidationException(
                            "Missing required field '{$requiredField}'",
                        ),
                    );
                }
            }
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($input as $field => $value) {
                if (!isset($schema['properties'][$field])) {
                    continue;
                }

                $propSchema = $schema['properties'][$field];
                $expectedType = $propSchema['type'] ?? null;

                if ($expectedType !== null) {
                    $valid = match ($expectedType) {
                        'string' => is_string($value),
                        'integer', 'number' => is_int($value),
                        'boolean' => is_bool($value),
                        'array' => is_array($value),
                        'object' => is_array($value),
                        'null' => $value === null,
                        default => true,
                    };

                    if (!$valid) {
                        return Result::err(
                            new ToolValidationException(
                                "Field '{$field}' must be of type {$expectedType}",
                            ),
                        );
                    }
                }
            }
        }

        return Result::ok(null);
    }
}
