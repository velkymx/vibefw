<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Closure;
use Fw\Aux\Notifications\AgentNotification;
use Fw\Aux\Notifications\Severity;
use Fw\Aux\Notifications\WebhookChannel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Item #53: `WebhookChannel::postViaStreams()` handed any caller-
 * supplied URL straight to `file_get_contents()`. That accepts
 * `file://`, `php://`, `gopher://`, etc., and resolves any hostname
 * to its real IP — including RFC1918 / loopback / link-local /
 * cloud-metadata ranges — giving a CRITICAL SSRF primitive: read
 * local files, pivot into internal services, exfiltrate cloud
 * instance credentials.
 *
 * [R57] narrows acceptable URLs:
 *   - Constructor: scheme must be http|https, host must be present
 *     (fail fast on structurally unsafe URLs)
 *   - deliver(): before any HTTP is attempted, the resolved IP(s)
 *     of the host are checked against a blocked-range list
 *     (loopback, private, link-local, IPv6 local, 0.0.0.0/8)
 *   - Opt-out via `allowInternalTargets: true` ctor flag for trusted
 *     internal webhooks
 */
final class WebhookChannelSsrfTest extends TestCase
{
    private function notification(): AgentNotification
    {
        return new AgentNotification(
            recipient: 'ops',
            subject: 'test',
            body: 'payload',
            severity: Severity::Info,
        );
    }

    #[Test]
    public function constructorRejectsFileScheme(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/scheme/i');

        new WebhookChannel('file:///etc/passwd');
    }

    #[Test]
    public function constructorRejectsPhpStreamScheme(): void
    {
        $this->expectException(RuntimeException::class);

        new WebhookChannel('php://filter/read=string.rot13/resource=/etc/passwd');
    }

    #[Test]
    public function constructorRejectsGopherScheme(): void
    {
        $this->expectException(RuntimeException::class);

        new WebhookChannel('gopher://evil.example/_');
    }

    #[Test]
    public function constructorRejectsJavascriptScheme(): void
    {
        $this->expectException(RuntimeException::class);

        new WebhookChannel('javascript:alert(1)');
    }

    #[Test]
    public function constructorRejectsSchemelessUrl(): void
    {
        $this->expectException(RuntimeException::class);

        new WebhookChannel('//evil.example/path');
    }

    #[Test]
    public function constructorRejectsUrlWithoutHost(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/host/i');

        new WebhookChannel('http:///no-host-here');
    }

    #[Test]
    public function constructorAcceptsWellFormedHttpsUrl(): void
    {
        $channel = new WebhookChannel('https://example.com/hooks/x');
        $this->assertInstanceOf(WebhookChannel::class, $channel);
    }

    #[Test]
    public function deliverRejectsLoopbackIpv4(): void
    {
        $channel = new WebhookChannel(
            url: 'http://127.0.0.1/webhook',
            httpCaller: static fn (): int => 200,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ssrf/i');

        $channel->deliver($this->notification());
    }

    #[Test]
    public function deliverRejectsPrivateIpv4(): void
    {
        $channel = new WebhookChannel(
            url: 'http://10.0.0.5/webhook',
            httpCaller: static fn (): int => 200,
        );

        $this->expectException(RuntimeException::class);

        $channel->deliver($this->notification());
    }

    #[Test]
    public function deliverRejectsCloudMetadataIp(): void
    {
        $channel = new WebhookChannel(
            url: 'http://169.254.169.254/latest/meta-data/',
            httpCaller: static fn (): int => 200,
        );

        $this->expectException(RuntimeException::class);

        $channel->deliver($this->notification());
    }

    #[Test]
    public function deliverRejectsIpv6Loopback(): void
    {
        $channel = new WebhookChannel(
            url: 'http://[::1]/webhook',
            httpCaller: static fn (): int => 200,
        );

        $this->expectException(RuntimeException::class);

        $channel->deliver($this->notification());
    }

    #[Test]
    public function deliverRejectsIpv6PrivateUla(): void
    {
        $channel = new WebhookChannel(
            url: 'http://[fc00::1]/webhook',
            httpCaller: static fn (): int => 200,
        );

        $this->expectException(RuntimeException::class);

        $channel->deliver($this->notification());
    }

    #[Test]
    public function allowInternalTargetsOptInBypassesIpGuard(): void
    {
        $captured = [];
        $httpCaller = Closure::fromCallable(
            static function (string $url, string $body, array $headers) use (&$captured): int {
                $captured = ['url' => $url, 'body' => $body, 'headers' => $headers];
                return 200;
            }
        );

        $channel = new WebhookChannel(
            url: 'http://127.0.0.1/internal-webhook',
            httpCaller: $httpCaller,
            allowInternalTargets: true,
        );

        $channel->deliver($this->notification());

        $this->assertSame('http://127.0.0.1/internal-webhook', $captured['url']);
    }

    #[Test]
    public function sourceGuardsUrlBeforeFileGetContents(): void
    {
        $rm = new ReflectionMethod(WebhookChannel::class, 'postViaStreams');
        $start = (int) $rm->getStartLine();
        $end = (int) $rm->getEndLine();
        $lines = file((string) $rm->getFileName()) ?: [];
        $body = implode('', array_slice($lines, $start - 1, $end - $start + 1));

        $this->assertStringContainsString(
            'file_get_contents',
            $body,
            'baseline: postViaStreams still uses file_get_contents'
        );

        // The deliver() method must call the URL guard before reaching postViaStreams.
        $deliver = new ReflectionMethod(WebhookChannel::class, 'deliver');
        $dStart = (int) $deliver->getStartLine();
        $dEnd = (int) $deliver->getEndLine();
        $deliverBody = implode('', array_slice($lines, $dStart - 1, $dEnd - $dStart + 1));

        $this->assertMatchesRegularExpression(
            '/assertHostNotInternal|assertUrlSafe/i',
            $deliverBody,
            'deliver() must invoke the SSRF guard before dispatching the HTTP call'
        );
    }

    #[Test]
    public function publicIpLiteralIsAllowedThroughCustomCaller(): void
    {
        $called = false;
        $channel = new WebhookChannel(
            url: 'http://203.0.113.10/webhook',
            httpCaller: static function () use (&$called): int {
                $called = true;
                return 200;
            },
        );

        $channel->deliver($this->notification());

        $this->assertTrue($called, 'public IP literal must pass SSRF guard and reach httpCaller');
    }
}
