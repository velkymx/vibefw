<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux\Notifications;

use Fw\Aux\Notifications\AgentNotification;
use Fw\Aux\Notifications\DeliverAgentNotificationJob;
use Fw\Aux\Notifications\Severity;
use PHPUnit\Framework\TestCase;

final class DeliverAgentNotificationJobTest extends TestCase
{
    protected function tearDown(): void
    {
        DeliverAgentNotificationJob::resetDeliverer();
    }

    public function testCarriesNotificationAndChannel(): void
    {
        $note = new AgentNotification('r', 's', 'b', Severity::Warn);
        $job = new DeliverAgentNotificationJob($note, channel: 'mail');

        $this->assertSame($note, $job->notification);
        $this->assertSame('mail', $job->channel);
    }

    public function testDefaultsChannelToLog(): void
    {
        $job = new DeliverAgentNotificationJob(new AgentNotification('r', 's', 'b'));
        $this->assertSame('log', $job->channel);
    }

    public function testHandleInvokesRegisteredDeliverer(): void
    {
        $seen = [];
        DeliverAgentNotificationJob::setDeliverer(function (AgentNotification $n, string $channel) use (&$seen) {
            $seen[] = [$channel, $n->subject];
        });

        $job = new DeliverAgentNotificationJob(new AgentNotification('r', 'hello', 'b'), 'mail');
        $job->handle();

        $this->assertSame([['mail', 'hello']], $seen);
    }

    public function testHandleWithoutDelivererIsSilent(): void
    {
        $job = new DeliverAgentNotificationJob(new AgentNotification('r', 's', 'b'));
        $job->handle();
        $this->assertTrue(true);
    }

    public function testQueueNameIsNotifications(): void
    {
        $job = new DeliverAgentNotificationJob(new AgentNotification('r', 's', 'b'));
        $this->assertSame('notifications', $job->getQueue());
    }
}
