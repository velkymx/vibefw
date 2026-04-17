<?php

declare(strict_types=1);

namespace Fw\Aux;

final readonly class Tool
{
    /**
     * @param array $inputSchema JSON Schema object as array
     * @param \Closure(array): WorkflowResult $handler Handler function
     * @param array<string> $abilities Token abilities required (any one suffices)
     * @param array $annotations MCP hints: readOnlyHint, destructiveHint, etc.
     * @param array $budget Non-spec hints: estimated_tokens, estimated_duration_ms, retry_policy
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public \Closure $handler,
        public array $abilities = [],
        public array $annotations = [],
        public array $budget = [],
    ) {}

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
