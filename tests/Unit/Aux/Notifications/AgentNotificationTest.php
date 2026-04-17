<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux\Notifications;

use Fw\Aux\Notifications\AgentNotification;
use Fw\Aux\Notifications\Severity;
use PHPUnit\Framework\TestCase;

final class AgentNotificationTest extends TestCase
{
    public function testConstructsWithAllFields(): void
    {
        $n = new AgentNotification(
            recipient: 'ops@example.com',
            subject: 'Queue drained',
            body: '5 tickets processed',
            severity: Severity::Info,
            metadata: ['queue_id' => 147],
        );

        $this->assertSame('ops@example.com', $n->recipient);
        $this->assertSame('Queue drained', $n->subject);
        $this->assertSame('5 tickets processed', $n->body);
        $this->assertSame(Severity::Info, $n->severity);
        $this->assertSame(['queue_id' => 147], $n->metadata);
    }

    public function testDefaultsSeverityInfoAndEmptyMetadata(): void
    {
        $n = new AgentNotification(
            recipient: 'ops@example.com',
            subject: 'Heartbeat',
            body: 'ok',
        );

        $this->assertSame(Severity::Info, $n->severity);
        $this->assertSame([], $n->metadata);
    }

    public function testRejectsBlankRecipient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AgentNotification(recipient: '', subject: 's', body: 'b');
    }

    public function testRejectsBlankSubject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AgentNotification(recipient: 'r', subject: '', body: 'b');
    }

    public function testToArrayRoundTrip(): void
    {
        $n = new AgentNotification(
            recipient: 'r',
            subject: 's',
            body: 'b',
            severity: Severity::Warn,
            metadata: ['k' => 'v'],
        );

        $this->assertSame(
            [
                'recipient' => 'r',
                'subject' => 's',
                'body' => 'b',
                'severity' => 'warn',
                'metadata' => ['k' => 'v'],
            ],
            $n->toArray(),
        );
    }

    public function testWithMetadataReturnsCloneWithMergedMetadata(): void
    {
        $original = new AgentNotification('r', 's', 'b', Severity::Info, ['a' => 1]);
        $updated = $original->withMetadata(['b' => 2]);

        $this->assertSame(['a' => 1], $original->metadata);
        $this->assertSame(['a' => 1, 'b' => 2], $updated->metadata);
    }

    public function testSeverityFromLabel(): void
    {
        $this->assertSame(Severity::Info, Severity::from('info'));
        $this->assertSame(Severity::Warn, Severity::from('warn'));
        $this->assertSame(Severity::Error, Severity::from('error'));
    }
}
