<?php

declare(strict_types=1);

namespace Fw\Aux\Notifications;

interface NotificationChannel
{
    public function deliver(AgentNotification $notification): void;
}
