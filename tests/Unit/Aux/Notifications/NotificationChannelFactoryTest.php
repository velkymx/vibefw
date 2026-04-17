<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux\Notifications;

use Fw\Aux\Notifications\LogChannel;
use Fw\Aux\Notifications\MailChannel;
use Fw\Aux\Notifications\NotificationChannelFactory;
use Fw\Aux\Notifications\WebhookChannel;
use Fw\Log\Logger;
use Fw\Log\LogLevel;
use PHPUnit\Framework\TestCase;

final class NotificationChannelFactoryTest extends TestCase
{
    public function testBuildsRegistryFromConfig(): void
    {
        $logger = Logger::create(sys_get_temp_dir(), LogLevel::INFO);
        $factory = new NotificationChannelFactory(fn() => $logger);

        $registry = $factory->build([
            'log' => ['driver' => 'log'],
            'mail' => ['driver' => 'mail', 'from' => 'agent@example.com'],
            'webhook' => ['driver' => 'webhook', 'url' => 'https://x.test/hook'],
        ]);

        $this->assertSame(['log', 'mail', 'webhook'], $registry->names());
    }

    public function testUnknownDriverThrows(): void
    {
        $factory = new NotificationChannelFactory(fn() => null);

        $this->expectException(\InvalidArgumentException::class);
        $factory->build(['x' => ['driver' => 'smoke-signal']]);
    }

    public function testMissingRequiredKeyThrows(): void
    {
        $factory = new NotificationChannelFactory(fn() => null);

        $this->expectException(\InvalidArgumentException::class);
        $factory->build(['mail' => ['driver' => 'mail']]);
    }

    public function testLogDriverUsesResolvedLogger(): void
    {
        $logger = Logger::create(sys_get_temp_dir(), LogLevel::INFO);
        $called = false;
        $factory = new NotificationChannelFactory(function () use ($logger, &$called) {
            $called = true;
            return $logger;
        });

        $registry = $factory->build(['log' => ['driver' => 'log']]);

        $this->assertTrue($called);
        $this->assertTrue($registry->has('log'));
    }
}
