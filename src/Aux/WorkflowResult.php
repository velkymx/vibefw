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
        public ?float $duration = null,
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

    public function withDuration(float $durationMs): self
    {
        return new self(
            completed: $this->completed,
            failed: $this->failed,
            pending: $this->pending,
            metadata: $this->metadata,
            isError: $this->isError,
            duration: $durationMs,
        );
    }

    public function merge(self ...$others): self
    {
        $completed = $this->completed;
        $failed = $this->failed;
        $pending = $this->pending;
        $metadata = $this->metadata;
        $isError = $this->isError;

        foreach ($others as $other) {
            $completed = array_merge($completed, $other->completed);
            $failed = array_merge($failed, $other->failed);
            $pending = array_merge($pending, $other->pending);
            $metadata = array_merge($metadata, $other->metadata);
            $isError = $isError || $other->isError;
        }

        return new self($completed, $failed, $pending, $metadata, $isError);
    }

    public function toMcpContent(): array
    {
        return [
            [
                'type' => 'text',
                'text' => json_encode($this->toArray(), JSON_THROW_ON_ERROR),
            ],
        ];
    }

    public function toArray(): array
    {
        $metadata = $this->metadata;
        if ($this->duration !== null) {
            $metadata['duration_ms'] = $this->duration;
        }

        return [
            'completed' => $this->completed,
            'failed' => $this->failed,
            'pending' => $this->pending,
            'metadata' => $metadata,
        ];
    }
}
