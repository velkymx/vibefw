<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux\Notifications;

use Fw\Aux\Events\AgentNotified;
use Fw\Aux\Notifications\AgentNotification;
use Fw\Aux\Notifications\NotificationChannel;
use Fw\Aux\Notifications\NotificationChannelRegistry;
use Fw\Aux\Notifications\Severity;
use Fw\Events\EventDispatcher;
use PHPUnit\Framework\TestCase;

final class AgentNotifiedEventTest extends TestCase
{
    public function testEventCarriesChannelAndNotification(): void
    {
        $n = new AgentNotification('r', 's', 'b', Severity::Info);
        $event = new AgentNotified('mail', $n);

        $this->assertSame('mail', $event->channel);
        $this->assertSame($n, $event->notification);
        $this->assertNotNull($event->occurredAt());
    }

    public function testRegistryDispatchesEventOnSuccessfulDelivery(): void
    {
        $dispatcher = new EventDispatcher();
        $seen = [];
        $dispatcher->listen(AgentNotified::class, function (AgentNotified $e) use (&$seen): void {
            $seen[] = [$e->channel, $e->notification->subject];
        });

        $channel = new class implements NotificationChannel {
            public function deliver(AgentNotification $notification): void {}
        };

        $registry = new NotificationChannelRegistry($dispatcher);
        $registry->register('log', $channel);
        $registry->deliver('log', new AgentNotification('r', 'hi', 'b'));

        $this->assertSame([['log', 'hi']], $seen);
    }

    public function testRegistryDoesNotDispatchWhenChannelThrows(): void
    {
        $dispatcher = new EventDispatcher();
        $fired = 0;
        $dispatcher->listen(AgentNotified::class, function () use (&$fired): void {
            $fired++;
        });

        $channel = new class implements NotificationChannel {
            public function deliver(AgentNotification $notification): void
            {
                throw new \RuntimeException('boom');
            }
        };

        $registry = new NotificationChannelRegistry($dispatcher);
        $registry->register('webhook', $channel);

        try {
            $registry->deliver('webhook', new AgentNotification('r', 's', 'b'));
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, $fired);
    }

    public function testNoDispatcherIsSafe(): void
    {
        $channel = new class implements NotificationChannel {
            public function deliver(AgentNotification $notification): void {}
        };

        $registry = new NotificationChannelRegistry();
        $registry->register('log', $channel);
        $registry->deliver('log', new AgentNotification('r', 's', 'b'));

        $this->assertTrue(true);
    }
}
