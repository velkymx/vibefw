<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Async;

use Fw\Async\AsyncHttp;
use Fw\Async\EventLoop;
use Fiber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * M2: AsyncHttp blocking DNS/TLS.
 *
 * validateHost() used gethostbynamel() which blocks the entire process
 * for up to 30s. The TLS handshake was also blocking. Both now yield
 * to the EventLoop between retries so peer fibers can progress.
 */
final class AsyncHttpNonBlockingDnsTlsTest extends TestCase
{
    private static function invokeResolveHostNonBlocking(string $host): array
    {
        $http = new AsyncHttp();
        $loop = new EventLoop();
        $m = (new ReflectionClass(AsyncHttp::class))->getMethod('resolveHostNonBlocking');

        $result = null;
        $exception = null;

        $fiber = new Fiber(function () use ($http, $host, $loop, $m, &$result, &$exception): void {
            try {
                $result = $m->invoke($http, $host, $loop);
            } catch (\Throwable $e) {
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
    public function resolveHostNonBlockingReturnsIpsForPublicDns(): void
    {
        $ips = self::invokeResolveHostNonBlocking('dns.google');
        $this->assertNotEmpty($ips, 'dns.google should resolve to at least one IP');
        foreach ($ips as $ip) {
            $this->assertTrue(
                filter_var($ip, FILTER_VALIDATE_IP) !== false,
                "Expected valid IP, got: {$ip}"
            );
        }
    }

    #[Test]
    public function resolveHostNonBlockingReturnsEmptyArrayForInvalidHost(): void
    {
        $ips = self::invokeResolveHostNonBlocking('no-such-host.invalid');
        $this->assertSame([], $ips, '.invalid TLD should not resolve');
    }

    #[Test]
    public function resolveHostNonBlockingYieldsToEventLoop(): void
    {
        $http = new AsyncHttp();
        $loop = new EventLoop();
        $m = (new ReflectionClass(AsyncHttp::class))->getMethod('resolveHostNonBlocking');

        $suspendedAtLeastOnce = false;

        $fiber = new Fiber(function () use ($http, $loop, $m, &$suspendedAtLeastOnce): void {
            try {
                $m->invoke($http, 'no-such-host.invalid', $loop);
            } catch (\Throwable) {
                // Expected — host doesn't resolve
            }
        });

        $fiber->start();

        while (!$fiber->isTerminated()) {
            if ($fiber->isSuspended()) {
                $suspendedAtLeastOnce = true;
                $fiber->resume();
            }
            $loop->tick();
        }

        $this->assertTrue($suspendedAtLeastOnce, 'resolveHostNonBlocking should Fiber::suspend() between retries');
    }

    #[Test]
    public function validateHostAcceptsEventLoopParameter(): void
    {
        $m = (new ReflectionClass(AsyncHttp::class))->getMethod('validateHost');
        $params = $m->getParameters();
        $this->assertCount(2, $params, 'validateHost should accept (string $host, EventLoop $loop)');
        $this->assertSame('loop', $params[1]->getName());
    }

    #[Test]
    public function performTlsHandshakeMethodExists(): void
    {
        $m = (new ReflectionClass(AsyncHttp::class))->getMethod('performTlsHandshake');
        $this->assertNotNull($m, 'performTlsHandshake method should exist');
        $params = $m->getParameters();
        $this->assertGreaterThanOrEqual(3, count($params), 'performTlsHandshake should accept socket, loop, deferred at minimum');
    }

    #[Test]
    public function sendRequestMethodExists(): void
    {
        $m = (new ReflectionClass(AsyncHttp::class))->getMethod('sendRequest');
        $this->assertNotNull($m, 'sendRequest method should exist');
    }

    #[Test]
    public function resolveHostNonBlockingMethodExists(): void
    {
        $m = (new ReflectionClass(AsyncHttp::class))->getMethod('resolveHostNonBlocking');
        $this->assertNotNull($m, 'resolveHostNonBlocking method should exist');
        $returnType = $m->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('array', (string) $returnType);
    }

    #[Test]
    public function fiberImportPresentInAsyncHttp(): void
    {
        $source = file_get_contents((new ReflectionClass(AsyncHttp::class))->getFileName());
        $this->assertStringContainsString('use Fiber;', $source, 'AsyncHttp must import Fiber for non-blocking yields');
    }
}
