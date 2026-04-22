<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Auth\Gate;
use Fw\Core\HttpKernel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Locks HttpKernel::resetState() into flushing every per-request
 * static cache. The Gate policy cache in particular has no lifetime
 * guarantee — if a policy is rebound at runtime, the cached instance
 * must not leak into the next request in worker mode.
 */
final class HttpKernelResetStateTest extends TestCase
{
    #[Test]
    public function resetStateFlushesGatePolicyCache(): void
    {
        $method = new ReflectionMethod(HttpKernel::class, 'resetState');

        $file = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($file, $start, $end - $start));

        $this->assertStringContainsString(
            'Gate::flushCache()',
            $body,
            'HttpKernel::resetState() must call Gate::flushCache() so '
            . 'policy instances do not leak between worker-mode requests.',
        );
    }

    #[Test]
    public function gateFlushCacheEmptiesPolicyCache(): void
    {
        $rc = new ReflectionClass(Gate::class);
        $prop = $rc->getProperty('policyCache');

        $prop->setValue(null, ['StubClass' => new class () extends \Fw\Auth\Policy {}]);
        $this->assertNotSame([], $prop->getValue(), 'sanity: cache should be populated before flush');

        Gate::flushCache();

        $this->assertSame(
            [],
            $prop->getValue(),
            'Gate::flushCache() must empty the static policy cache so the next '
            . 'resolvePolicy() re-reads the current class_exists state.',
        );
    }
}
