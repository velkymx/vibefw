<?php

declare(strict_types=1);

namespace Fw\Aux\Notifications;

use Fw\Log\Logger;
use Fw\Log\LogLevel;

final class LogChannel implements NotificationChannel
{
    public function __construct(
        private readonly Logger $logger,
    ) {}

    public function deliver(AgentNotification $notification): void
    {
        $this->logger->log(
            $this->severityToLogLevel($notification->severity),
            $notification->subject,
            [
                'recipient' => $notification->recipient,
                'body' => $notification->body,
                ...$notification->metadata,
            ],
        );
    }

    private function severityToLogLevel(Severity $severity): LogLevel
    {
        return match ($severity) {
            Severity::Info => LogLevel::INFO,
            Severity::Warn => LogLevel::WARNING,
            Severity::Error => LogLevel::ERROR,
        };
    }
}
