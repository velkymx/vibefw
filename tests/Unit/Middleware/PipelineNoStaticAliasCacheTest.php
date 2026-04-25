<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Middleware;

use Fw\Middleware\Pipeline;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Item H14 — Pipeline must not use a static alias cache that survives
 * across requests in worker mode. The old code cached the middleware.php
 * aliases in a static property — deploys that touched middleware config
 * required a full worker restart to take effect.
 *
 * The fixed code loads aliases exclusively from the container
 * (set by MiddlewareServiceProvider), which is per-application and
 * always fresh.
 */
final class PipelineNoStaticAliasCacheTest extends TestCase
{
    #[Test]
    public function pipelineDoesNotHaveStaticAliasCacheProperty(): void
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
            'Pipeline must not have a static $cachedFileAliases property — aliases should come from the container, not a process-wide cache.',
        );
    }

    #[Test]
    public function pipelineDoesNotHaveClearAliasCacheMethod(): void
    {
        $rc = new ReflectionClass(Pipeline::class);
        $methods = array_map(fn ($m) => $m->getName(), $rc->getMethods());

        $this->assertNotContains(
            'clearAliasCache',
            $methods,
            'Pipeline must not have a clearAliasCache() static method — the static cache it cleared should not exist.',
        );
    }

    #[Test]
    public function loadAliasesDoesNotUseStaticCache(): void
    {
        $rc = new ReflectionClass(Pipeline::class);
        $method = $rc->getMethod('loadAliases');
        $file = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($file, $start, $end - $start));

        $this->assertStringNotContainsString(
            'self::$cachedFileAliases',
            $body,
            'loadAliases() must not reference a static $cachedFileAliases property.',
        );
        $this->assertStringNotContainsString(
            'require $configFile',
            $body,
            'loadAliases() must not directly require the config file — it should load from the container.',
        );
    }
}
