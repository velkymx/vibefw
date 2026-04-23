<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Async\AsyncHttp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Item #31: `validateHost()` resolved DNS once, checked the IPs, and
 * returned `void`. A few milliseconds later `stream_socket_client`
 * resolved the same hostname *again*, which a DNS rebinding attacker
 * could flip to point at a private IP. The check passed; the
 * connection landed on the attacker's target.
 *
 * After [R36] `validateHost()` returns the resolved IP it actually
 * approved, and `executeAsync` connects to that IP directly with
 * the original hostname preserved as the Host header (and as SNI /
 * peer_name for TLS). One lookup, one connect.
 */
final class AsyncHttpSsrfRebindingTest extends TestCase
{
    private static function validateHost(string $host): string
    {
        $http = new AsyncHttp();
        $m = (new ReflectionClass(AsyncHttp::class))->getMethod('validateHost');
        /** @var string $ip */
        $ip = $m->invoke($http, $host);
        return $ip;
    }

    #[Test]
    public function returnsResolvedIpForPublicHost(): void
    {
        // 8.8.8.8 is a numeric public IP; gethostbynamel returns [$ip].
        $ip = self::validateHost('8.8.8.8');
        $this->assertSame('8.8.8.8', $ip);
    }

    #[Test]
    public function rejectsLoopbackIpv4(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('127.0.0.1');
        self::validateHost('127.0.0.1');
    }

    #[Test]
    public function rejectsPrivateClassA(): void
    {
        $this->expectException(RuntimeException::class);
        self::validateHost('10.0.0.1');
    }

    #[Test]
    public function rejectsCloudMetadataAddress(): void
    {
        $this->expectException(RuntimeException::class);
        self::validateHost('169.254.169.254');
    }

    #[Test]
    public function rejectsUnresolvableHost(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not resolve');
        // `.invalid` TLD is guaranteed never to resolve (RFC 2606).
        self::validateHost('no-such-host-exists.invalid');
    }

    #[Test]
    public function validateHostReturnTypeIsString(): void
    {
        $m = (new ReflectionClass(AsyncHttp::class))->getMethod('validateHost');
        $type = $m->getReturnType();
        $this->assertNotNull($type);
        $this->assertSame('string', (string) $type);
    }
}
