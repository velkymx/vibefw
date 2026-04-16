<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\HttpKernel;
use Fw\Middleware\Pipeline;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * M-03: HttpKernel::resetState() must clear Pipeline's alias cache so that
 * middleware.php changes take effect on the next request without a restart.
 */
final class PipelineAliasCacheResetTest extends TestCase
{
    #[Test]
    public function httpKernelResetStateCallsClearAliasCache(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Core/HttpKernel.php');
        $this->assertIsString($source);

        $this->assertStringContainsString(
            'Pipeline::clearAliasCache()',
            $source,
            'HttpKernel::resetState() must call Pipeline::clearAliasCache() so ' .
            'middleware config changes take effect on the next request without a process restart'
        );
    }

    #[Test]
    public function clearAliasCacheNullsTheStaticProperty(): void
    {
        // Populate the cache by reading the Reflection property then setting it
        $ref  = new ReflectionClass(Pipeline::class);
        $prop = $ref->getProperty('cachedFileAliases');

        // Seed a non-null value to confirm clearAliasCache() actually resets it
        $prop->setValue(null, ['auth' => 'App\\Middleware\\AuthMiddleware']);
        $this->assertNotNull($prop->getValue(null));

        Pipeline::clearAliasCache();

        $this->assertNull($prop->getValue(null), 'clearAliasCache() must set $cachedFileAliases to null');
    }
}
