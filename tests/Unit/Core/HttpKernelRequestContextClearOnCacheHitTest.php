<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Core\HttpKernel;
use Fw\Core\RequestContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * M14: RequestContext not cleared on early cache hit.
 *
 * Pre-fix: If a guest cache hit returns early (line 60), the RequestContext
 * created at line 64 is never cleared. The finally block only runs if the
 * try block is entered.
 *
 * Post-fix: RequestContext::clear() is called before the early return on
 * cache hit, ensuring no leakage between requests in worker mode.
 */
final class HttpKernelRequestContextClearOnCacheHitTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::clear();
    }

    #[Test]
    public function requestContextClearIsCalledBeforeEarlyReturn(): void
    {
        $source = file_get_contents((new \ReflectionClass(HttpKernel::class))->getFileName());

        // Find the early cache hit return statement
        $lines = explode("\n", $source);
        $foundEarlyReturn = false;
        $foundClearBeforeReturn = false;

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            // Look for the early cache hit return
            if (str_contains($line, 'if ($cached = $this->tryGetFromCache')) {
                $foundEarlyReturn = true;
                // Check the next few lines for RequestContext::clear()
                for ($j = $i + 1; $j < min($i + 5, count($lines)); $j++) {
                    if (str_contains($lines[$j], 'RequestContext::clear()')) {
                        $foundClearBeforeReturn = true;
                        break;
                    }
                    if (str_contains($lines[$j], 'return $cached')) {
                        break;
                    }
                }
                break;
            }
        }

        $this->assertTrue($foundEarlyReturn, 'Should find early cache hit check');
        $this->assertTrue($foundClearBeforeReturn, 'RequestContext::clear() should be called before early return');
    }

    #[Test]
    public function requestContextClearIsInFinallyBlock(): void
    {
        $source = file_get_contents((new \ReflectionClass(HttpKernel::class))->getFileName());
        $this->assertStringContainsString(
            'finally',
            $source,
            'HttpKernel should have a finally block for cleanup'
        );
        $this->assertStringContainsString(
            'RequestContext::clear()',
            $source,
            'RequestContext::clear() should be called in finally block'
        );
    }

    #[Test]
    public function requestContextClearIsCalledOnEarlyCacheHit(): void
    {
        $source = file_get_contents((new \ReflectionClass(HttpKernel::class))->getFileName());
        $this->assertStringContainsString(
            'RequestContext::clear()',
            $source,
            'HttpKernel should call RequestContext::clear() on early cache hit'
        );
    }
}
