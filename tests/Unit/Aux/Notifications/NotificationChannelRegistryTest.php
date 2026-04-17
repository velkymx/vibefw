<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux\Notifications;

use Fw\Aux\Notifications\AgentNotification;
use Fw\Aux\Notifications\NotificationChannel;
use Fw\Aux\Notifications\NotificationChannelRegistry;
use PHPUnit\Framework\TestCase;

final class NotificationChannelRegistryTest extends TestCase
{
    public function testRegisterAndDeliver(): void
    {
        $seen = [];
        $channel = new class($seen) implements NotificationChannel {
            public function __construct(public array &$seen) {}
            public function deliver(AgentNotification $notification): void
            {
                $this->seen[] = $notification->subject;
            }
        };

        $registry = new NotificationChannelRegistry();
        $registry->register('mail', $channel);

        $registry->deliver('mail', new AgentNotification('r', 'hi', 'b'));

        $this->assertSame(['hi'], $seen);
    }

    public function testUnknownChannelThrows(): void
    {
        $registry = new NotificationChannelRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $registry->deliver('missing', new AgentNotification('r', 's', 'b'));
    }

    public function testHasReflectsRegistration(): void
    {
        $registry = new NotificationChannelRegistry();

        $this->assertFalse($registry->has('mail'));

        $channel = new class implements NotificationChannel {
            public function deliver(AgentNotification $notification): void {}
        };
        $registry->register('mail', $channel);

        $this->assertTrue($registry->has('mail'));
    }

    public function testNamesLists(): void
    {
        $registry = new NotificationChannelRegistry();
        $noop = new class implements NotificationChannel {
            public function deliver(AgentNotification $notification): void {}
        };
        $registry->register('log', $noop);
        $registry->register('mail', $noop);

        $this->assertSame(['log', 'mail'], $registry->names());
    }
}
