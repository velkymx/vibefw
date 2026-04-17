<?php

declare(strict_types=1);

namespace Fw\Aux\Events;

use DateTimeImmutable;
use Fw\Aux\Notifications\AgentNotification;
use Fw\Events\Event;

final readonly class AgentNotified implements Event
{
    public DateTimeImmutable $occurredAt;

    public function __construct(
        public string $channel,
        public AgentNotification $notification,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
