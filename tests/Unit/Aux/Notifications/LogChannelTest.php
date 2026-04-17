<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux\Notifications;

use Fw\Aux\Notifications\AgentNotification;
use Fw\Aux\Notifications\LogChannel;
use Fw\Aux\Notifications\Severity;
use Fw\Log\Logger;
use Fw\Log\LogLevel;
use PHPUnit\Framework\TestCase;

final class LogChannelTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = sys_get_temp_dir() . '/fw_logchan_' . bin2hex(random_bytes(4));
        mkdir($this->logPath, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logPath . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->logPath);
    }

    public function testDeliverWritesInfoForInfoSeverity(): void
    {
        $logger = Logger::create($this->logPath, LogLevel::DEBUG);
        $channel = new LogChannel($logger);

        $channel->deliver(new AgentNotification('ops', 'hi', 'body', Severity::Info));

        $this->assertStringContainsString('hi', $this->readLog());
        $this->assertStringContainsString('INFO', $this->readLog());
    }

    public function testDeliverWritesErrorForErrorSeverity(): void
    {
        $logger = Logger::create($this->logPath, LogLevel::DEBUG);
        $channel = new LogChannel($logger);

        $channel->deliver(new AgentNotification('ops', 'down', 'DB', Severity::Error));

        $this->assertStringContainsString('ERROR', $this->readLog());
        $this->assertStringContainsString('down', $this->readLog());
    }

    public function testDeliverIncludesRecipientAndMetadataInContext(): void
    {
        $logger = Logger::create($this->logPath, LogLevel::DEBUG);
        $channel = new LogChannel($logger);

        $channel->deliver(new AgentNotification(
            recipient: 'ops@example.com',
            subject: 'q',
            body: 'b',
            severity: Severity::Warn,
            metadata: ['queue_id' => 7],
        ));

        $log = $this->readLog();
        $this->assertStringContainsString('ops@example.com', $log);
        $this->assertStringContainsString('7', $log);
        $this->assertStringContainsString('WARN', $log);
    }

    private function readLog(): string
    {
        $files = glob($this->logPath . '/*') ?: [];
        return $files === [] ? '' : (file_get_contents($files[0]) ?: '');
    }
}
