<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Async\AsyncHttp;
use Fw\Async\EventLoop;
use Fiber;
use PHPUnit\Framework\Attributes\Test;
use Throwable;
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
    private static function validateHostViaReflection(AsyncHttp $http, string $host, EventLoop $loop): string
    {
        $m = (new ReflectionClass(AsyncHttp::class))->getMethod('validateHost');
        /** @var string $ip */
        $ip = $m->invoke($http, $host, $loop);
        return $ip;
    }

    private static function validateHostIpLiteral(string $host): string
    {
        return self::validateHostViaReflection(new AsyncHttp(), $host, new EventLoop());
    }

    private static function validateHostWithFiber(string $host): string
    {
        $http = new AsyncHttp();
        $loop = new EventLoop();
        $result = null;
        $exception = null;

        $fiber = new Fiber(function () use ($http, $host, $loop, &$result, &$exception): void {
            try {
                $result = self::validateHostViaReflection($http, $host, $loop);
            } catch (Throwable $e) {
                $exception = $e;
            }
        });

        $fiber->start();

        while (!$fiber->isTerminated()) {
            $loop->tick();
            if ($fiber->isSuspended()) {
                $fiber->resume();
            }
        }

        if ($exception !== null) {
            throw $exception;
        }

        return $result;
    }

    #[Test]
    public function returnsResolvedIpForPublicHost(): void
    {
        $ip = self::validateHostIpLiteral('8.8.8.8');
        $this->assertSame('8.8.8.8', $ip);
    }

    #[Test]
    public function rejectsLoopbackIpv4(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('127.0.0.1');
        self::validateHostIpLiteral('127.0.0.1');
    }

    #[Test]
    public function rejectsPrivateClassA(): void
    {
        $this->expectException(RuntimeException::class);
        self::validateHostIpLiteral('10.0.0.1');
    }

    #[Test]
    public function rejectsCloudMetadataAddress(): void
    {
        $this->expectException(RuntimeException::class);
        self::validateHostIpLiteral('169.254.169.254');
    }

    #[Test]
    public function rejectsUnresolvableHost(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not resolve');
        self::validateHostWithFiber('no-such-host-exists.invalid');
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
