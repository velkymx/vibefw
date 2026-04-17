<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux\Notifications;

use Fw\Aux\Notifications\AgentNotification;
use Fw\Aux\Notifications\Severity;
use Fw\Aux\Notifications\WebhookChannel;
use PHPUnit\Framework\TestCase;

final class WebhookChannelTest extends TestCase
{
    public function testPostsJsonPayloadToConfiguredUrl(): void
    {
        $captured = null;
        $channel = new WebhookChannel(
            url: 'https://example.com/hooks/agent',
            timeout: 5,
            httpCaller: function (string $url, string $body, array $headers) use (&$captured): int {
                $captured = compact('url', 'body', 'headers');
                return 200;
            },
        );

        $channel->deliver(new AgentNotification(
            recipient: 'ops',
            subject: 'Queue drained',
            body: 'done',
            severity: Severity::Warn,
            metadata: ['queue_id' => 7],
        ));

        $this->assertSame('https://example.com/hooks/agent', $captured['url']);

        $decoded = json_decode($captured['body'], true);
        $this->assertSame('Queue drained', $decoded['subject']);
        $this->assertSame('warn', $decoded['severity']);
        $this->assertSame(7, $decoded['metadata']['queue_id']);

        $this->assertContains('Content-Type: application/json', $captured['headers']);
    }

    public function testNon2xxResponseThrows(): void
    {
        $channel = new WebhookChannel(
            url: 'https://example.com/hooks',
            timeout: 5,
            httpCaller: fn() => 500,
        );

        $this->expectException(\RuntimeException::class);
        $channel->deliver(new AgentNotification('r', 's', 'b'));
    }
}
