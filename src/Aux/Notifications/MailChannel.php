<?php

declare(strict_types=1);

namespace Fw\Aux\Notifications;

final class MailChannel implements NotificationChannel
{
    /**
     * @param \Closure(string $to, string $subject, string $body, string $headers): bool|null $mailer
     */
    public function __construct(
        private readonly string $fromAddress,
        private readonly ?\Closure $mailer = null,
    ) {}

    public function deliver(AgentNotification $notification): void
    {
        $subject = sprintf('[%s] %s', strtoupper($notification->severity->value), $notification->subject);
        $headers = "From: {$this->fromAddress}\r\nContent-Type: text/plain; charset=UTF-8\r\n";

        $sent = $this->mailer !== null
            ? ($this->mailer)($notification->recipient, $subject, $notification->body, $headers)
            : mail($notification->recipient, $subject, $notification->body, $headers);

        if ($sent === false) {
            throw new \RuntimeException("MailChannel failed to deliver to {$notification->recipient}.");
        }
    }
}
