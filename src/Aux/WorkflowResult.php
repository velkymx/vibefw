<?php

declare(strict_types=1);

namespace Fw\Aux;

final readonly class WorkflowResult
{
    /**
     * @param array<int, array<string, mixed>> $completed Items successfully processed
     * @param array<int, array<string, mixed>> $failed Items that failed processing
     * @param array<int, array<string, mixed>> $pending Items awaiting further action
     * @param array<string, mixed> $metadata Additional workflow metadata
     */
    public function __construct(
        public array $completed,
        public array $failed,
        public array $pending,
        public array $metadata,
        public bool $isError,
    ) {}

    public static function success(array $completed = [], array $metadata = []): self
    {
        return new self(
            completed: $completed,
            failed: [],
            pending: [],
            metadata: $metadata,
            isError: false,
        );
    }

    public static function partial(
        array $completed,
        array $failed,
        array $pending = [],
        array $metadata = [],
    ): self {
        return new self(
            completed: $completed,
            failed: $failed,
            pending: $pending,
            metadata: $metadata,
            isError: false,
        );
    }

    public static function error(string $reason, array $metadata = []): self
    {
        return new self(
            completed: [],
            failed: [],
            pending: [],
            metadata: array_merge(['error' => $reason], $metadata),
            isError: true,
        );
    }

    public function toMcpContent(): array
    {
        return [
            [
                'type' => 'tool_result',
                'result' => json_encode($this->toArray()),
            ],
        ];
    }

    public function toArray(): array
    {
        return [
            'completed' => $this->completed,
            'failed' => $this->failed,
            'pending' => $this->pending,
            'metadata' => $this->metadata,
        ];
    }
}
