<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Cache\CacheInterface;
use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Middleware\RateLimitMiddleware;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * M5: Cache failure must log SECURITY warning + still pass request through.
 */
final class RateLimitMiddlewareTest extends TestCase
{
    private function buildMiddleware(CacheInterface $cache): RateLimitMiddleware
    {
        $app = Application::getInstance();
        return new RateLimitMiddleware($app, $cache);
    }

    /** @return CacheInterface&\PHPUnit\Framework\MockObject\MockObject */
    private function failingCache(): CacheInterface
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('increment')->willReturn(false);
        return $cache;
    }

    #[Test]
    public function cacheFailureLogsToErrorLog(): void
    {
        $middleware = $this->buildMiddleware($this->failingCache());
        $request    = new Request();
        $next       = static fn (Request $r): Response => new Response();

        // Capture error_log() output via ini log_errors + error_log destination
        $tmpLog = tempnam(sys_get_temp_dir(), 'fw_test_');
        $prevLog    = ini_set('error_log', $tmpLog);
        $prevErrors = ini_set('log_errors', '1');

        try {
            $middleware->handle($request, $next);
        } finally {
            ini_set('error_log', $prevLog ?: '');
            ini_set('log_errors', $prevErrors ?: '0');
        }

        $logContent = file_get_contents($tmpLog);
        unlink($tmpLog);

        $this->assertStringContainsStringIgnoringCase(
            'rate limit',
            $logContent,
            'SECURITY: rate limit cache failure must be logged'
        );
    }

    #[Test]
    public function cacheFailureStillPassesRequestThrough(): void
    {
        $middleware = $this->buildMiddleware($this->failingCache());
        $request    = new Request();
        $called     = false;
        $next       = static function (Request $r) use (&$called): Response {
            $called = true;
            return new Response();
        };

        $middleware->handle($request, $next);

        $this->assertTrue($called, 'Request must still reach handler on cache failure');
    }

    #[Test]
    public function normalRequestPassesThroughWhenUnderLimit(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('increment')->willReturn(5); // under 60 default

        $middleware = $this->buildMiddleware($cache);
        $request    = new Request();
        $next       = static fn (Request $r): Response => (new Response())->setStatus(200);

        $response = $middleware->handle($request, $next);

        $this->assertInstanceOf(Response::class, $response);
    }
}
