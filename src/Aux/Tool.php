<?php

declare(strict_types=1);

namespace Fw\Aux;

use Closure;

final readonly class Tool
{
    /**
     * @param array<string, mixed> $inputSchema JSON Schema object as array
     * @param Closure(array<string, mixed>): WorkflowResult $handler Handler function
     * @param list<string> $abilities Token abilities required (any one suffices)
     * @param array<string, mixed> $annotations MCP hints: readOnlyHint, destructiveHint, etc.
     * @param array<string, mixed> $budget Non-spec hints: estimated_tokens, estimated_duration_ms, retry_policy
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public Closure $handler,
        public array $abilities = [],
        public array $annotations = [],
        public array $budget = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toMcpShape(): array
    {
        $shape = [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
        ];

        $annotations = $this->annotations;
        if ($this->budget !== []) {
            $annotations['_budget'] = $this->budget;
        }

        if ($annotations !== []) {
            $shape['annotations'] = $annotations;
        }

        return $shape;
    }

    /**
     * @param list<string> $abilities
     */
    public function withAbilities(array $abilities): self
    {
        return new self(
            name: $this->name,
            description: $this->description,
            inputSchema: $this->inputSchema,
            handler: $this->handler,
            abilities: $abilities,
            annotations: $this->annotations,
            budget: $this->budget,
        );
    }

    /**
     * @param list<string> $callerAbilities
     */
    public function isAccessibleWith(array $callerAbilities): bool
    {
        if ($this->abilities === []) {
            return true;
        }

        foreach ($this->abilities as $required) {
            if (in_array($required, $callerAbilities, true)) {
                return true;
            }
        }

        return false;
    }
}
