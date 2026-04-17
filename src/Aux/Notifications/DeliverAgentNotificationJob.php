<?php

declare(strict_types=1);

namespace Fw\Aux\Notifications;

use Fw\Queue\Job;

final class DeliverAgentNotificationJob extends Job
{
    /**
     * @var \Closure(AgentNotification $notification, string $channel): void|null
     */
    private static ?\Closure $deliverer = null;

    protected string $queue = 'notifications';

    public function __construct(
        public readonly AgentNotification $notification,
        public readonly string $channel = 'log',
    ) {}

    public function handle(): void
    {
        if (self::$deliverer === null) {
            return;
        }

        (self::$deliverer)($this->notification, $this->channel);
    }

    /**
     * @param \Closure(AgentNotification, string): void $deliverer
     */
    public static function setDeliverer(\Closure $deliverer): void
    {
        self::$deliverer = $deliverer;
    }

    public static function resetDeliverer(): void
    {
        self::$deliverer = null;
    }
}
