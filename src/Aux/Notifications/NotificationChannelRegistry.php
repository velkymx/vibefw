<?php

declare(strict_types=1);

namespace Fw\Aux\Notifications;

use Fw\Aux\Events\AgentNotified;
use Fw\Events\EventDispatcher;
use InvalidArgumentException;

final class NotificationChannelRegistry
{
    /** @var array<string, NotificationChannel> */
    private array $channels = [];

    public function __construct(
        private readonly ?EventDispatcher $events = null,
    ) {}

    public function register(string $name, NotificationChannel $channel): self
    {
        $this->channels[$name] = $channel;
        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->channels[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->channels);
    }

    public function deliver(string $name, AgentNotification $notification): void
    {
        if (!isset($this->channels[$name])) {
            throw new InvalidArgumentException("Notification channel '{$name}' is not registered.");
        }

        $this->channels[$name]->deliver($notification);

        $this->events?->dispatch(new AgentNotified($name, $notification));
    }
}
