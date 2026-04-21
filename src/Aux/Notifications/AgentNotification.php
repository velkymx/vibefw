<?php

declare(strict_types=1);

namespace Fw\Aux\Notifications;

use InvalidArgumentException;

final readonly class AgentNotification
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $recipient,
        public string $subject,
        public string $body,
        public Severity $severity = Severity::Info,
        public array $metadata = [],
    ) {
        if (trim($recipient) === '') {
            throw new InvalidArgumentException('AgentNotification recipient must not be blank.');
        }
        if (trim($subject) === '') {
            throw new InvalidArgumentException('AgentNotification subject must not be blank.');
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function withMetadata(array $extra): self
    {
        return clone($this, ['metadata' => [...$this->metadata, ...$extra]]);
    }

    /**
     * @return array{recipient: string, subject: string, body: string, severity: string, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'recipient' => $this->recipient,
            'subject' => $this->subject,
            'body' => $this->body,
            'severity' => $this->severity->value,
            'metadata' => $this->metadata,
        ];
    }
}
