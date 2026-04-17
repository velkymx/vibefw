<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux\Notifications;

use Fw\Aux\Notifications\AgentNotification;
use Fw\Aux\Notifications\MailChannel;
use Fw\Aux\Notifications\Severity;
use PHPUnit\Framework\TestCase;

final class MailChannelTest extends TestCase
{
    public function testSendsMailWithSeverityTaggedSubject(): void
    {
        $captured = null;
        $channel = new MailChannel(
            fromAddress: 'agent@example.com',
            mailer: function (string $to, string $subject, string $body, string $headers) use (&$captured): bool {
                $captured = compact('to', 'subject', 'body', 'headers');
                return true;
            },
        );

        $channel->deliver(new AgentNotification(
            recipient: 'ops@example.com',
            subject: 'Down',
            body: 'DB unreachable',
            severity: Severity::Error,
        ));

        $this->assertSame('ops@example.com', $captured['to']);
        $this->assertStringContainsString('[ERROR] Down', $captured['subject']);
        $this->assertStringContainsString('DB unreachable', $captured['body']);
        $this->assertStringContainsString('From: agent@example.com', $captured['headers']);
    }

    public function testMailerFailureThrows(): void
    {
        $channel = new MailChannel(
            fromAddress: 'a@b',
            mailer: fn() => false,
        );

        $this->expectException(\RuntimeException::class);
        $channel->deliver(new AgentNotification('r@b', 's', 'b'));
    }
}
