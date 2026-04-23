<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Http\ApiResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Item #29: `ApiResponse::send()` calls `exit`, which in worker mode
 * (FrankenPHP, RoadRunner, Swoole, etc.) terminates the worker
 * process, not just the current request. The TODO accepts the
 * existing `@deprecated` tag as the fix but asks for explicit
 * documentation that this method must not be used in async/worker
 * contexts.
 *
 * Lock the docblock contract in place with a reflection test so a
 * future edit can't silently drop the deprecation or soften the
 * worker-mode warning.
 */
final class ApiResponseSendDeprecationTest extends TestCase
{
    #[Test]
    public function sendIsDeprecated(): void
    {
        $method = (new ReflectionClass(ApiResponse::class))->getMethod('send');
        $doc = $method->getDocComment() ?: '';

        $this->assertStringContainsString('@deprecated', $doc);
    }

    #[Test]
    public function sendDocblockWarnsAboutWorkerMode(): void
    {
        $method = (new ReflectionClass(ApiResponse::class))->getMethod('send');
        $doc = $method->getDocComment() ?: '';

        $this->assertMatchesRegularExpression(
            '/worker/i',
            $doc,
            'send() must call out worker-mode hazards explicitly'
        );
        $this->assertMatchesRegularExpression(
            '/exit|terminat/i',
            $doc,
            'send() must make the process-kill behavior explicit'
        );
    }

    #[Test]
    public function sendReturnsNever(): void
    {
        $method = (new ReflectionClass(ApiResponse::class))->getMethod('send');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame('never', (string) $returnType);
    }
}
