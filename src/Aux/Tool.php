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
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public \Closure $handler,
        public array $abilities = [],
        public array $annotations = [],
    ) {}

    public function toMcpShape(): array
    {
        $shape = [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
        ];

        if ($this->annotations !== []) {
            $shape['annotations'] = $this->annotations;
        }

        return $shape;
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
