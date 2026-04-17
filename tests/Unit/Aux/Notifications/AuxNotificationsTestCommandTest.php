<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux\Notifications;

use Fw\Console\Commands\AuxNotificationsTestCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AuxNotificationsTestCommandTest extends TestCase
{
    public function testCommandExists(): void
    {
        $this->assertTrue(class_exists(AuxNotificationsTestCommand::class));
    }

    public function testCommandHasCorrectName(): void
    {
        $rc = new ReflectionClass(AuxNotificationsTestCommand::class);
        $prop = $rc->getProperty('name');
        $this->assertSame('aux:notifications:test', $prop->getDefaultValue());
    }

    public function testConfigureDeclaresChannelArgument(): void
    {
        $rc = new ReflectionClass(AuxNotificationsTestCommand::class);
        $method = $rc->getMethod('configure');

        $body = $this->methodBody($method);
        $this->assertStringContainsString("addArgument('channel'", $body);
    }

    public function testHandleResolvesRegistryAndDelivers(): void
    {
        $rc = new ReflectionClass(AuxNotificationsTestCommand::class);
        $body = $this->methodBody($rc->getMethod('handle'));

        $this->assertStringContainsString('NotificationChannelRegistry', $body);
        $this->assertStringContainsString('->deliver(', $body);
        $this->assertStringContainsString('AgentNotification', $body);
    }

    public function testCommandRegisteredInApplication(): void
    {
        $rc = new ReflectionClass(\Fw\Console\Application::class);
        $body = $this->methodBody($rc->getMethod('registerBuiltinCommands'));

        $this->assertStringContainsString('AuxNotificationsTestCommand', $body);
    }

    private function methodBody(\ReflectionMethod $method): string
    {
        $file = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        return implode('', array_slice($file, $start, $end - $start));
    }
}
