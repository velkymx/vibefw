<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\HttpKernel;
use Fw\Middleware\Pipeline;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * H14: Pipeline must not use a static alias cache that survives across
 * requests in worker mode. Aliases are loaded from the container
 * (set by MiddlewareServiceProvider) per-instance, so config changes
 * are always picked up without needing a cache-clear call.
 */
final class PipelineAliasCacheResetTest extends TestCase
{
    #[Test]
    public function httpKernelResetStateDoesNotReferenceClearAliasCache(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Core/HttpKernel.php');
        $this->assertIsString($source);

        $this->assertStringNotContainsString(
            'Pipeline::clearAliasCache()',
            $source,
            'HttpKernel::resetState() must not call Pipeline::clearAliasCache() — the static cache it cleared no longer exists.',
        );
    }

    #[Test]
    public function pipelineHasNoStaticAliasCacheProperty(): void
    {
        $rc = new ReflectionClass(Pipeline::class);
        $staticProps = array_filter(
            $rc->getProperties(),
            fn ($p) => $p->isStatic(),
        );
        $staticNames = array_map(fn ($p) => $p->getName(), $staticProps);

        $this->assertNotContains(
            'cachedFileAliases',
            $staticNames,
            'Pipeline must not have a static $cachedFileAliases property.',
        );
    }
}
