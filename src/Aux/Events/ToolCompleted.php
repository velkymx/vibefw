<?php

declare(strict_types=1);

namespace Fw\Aux\Events;

use DateTimeImmutable;
use Fw\Aux\WorkflowResult;
use Fw\Events\Event;

final readonly class ToolCompleted implements Event
{
    public DateTimeImmutable $occurredAt;

    public function __construct(
        public string $toolName,
        public WorkflowResult $result,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
