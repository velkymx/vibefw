<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Cache\ApcuCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Item #37: `ApcuCache::increment()` (and its write-side siblings)
 * passed `$ttl ?? 0` to `apcu_store()`. APCu itself treats 0 as "no
 * expiry", but the framework's own `CacheInterface` contract reads a
 * null TTL as "use driver default" — so leaking that null through to
 * `0` ties the framework to APCu-specific semantics, and on any APCu
 * configuration (or interpretation) where `0` means "expire now" the
 * key vanishes immediately.
 *
 * After [R42] `ApcuCache` takes a `$defaultTtl = 3600` constructor
 * parameter (matches `FileCache`), stores it as a property, and every
 * write path (`set`, `setMany`, `remember`, `increment`) falls back
 * to `$this->defaultTtl` instead of `0` when no explicit TTL is
 * given. The APCu extension is not loaded in this test environment,
 * so the contract is pinned via source-level assertions that can run
 * without instantiating the class.
 */
final class ApcuCacheDefaultTtlContractTest extends TestCase
{
    private function source(): string
    {
        $path = (new ReflectionClass(ApcuCache::class))->getFileName();
        $this->assertIsString($path);
        return (string) file_get_contents($path);
    }

    #[Test]
    public function constructorAcceptsDefaultTtlParameter(): void
    {
        $ctor = (new ReflectionClass(ApcuCache::class))->getConstructor();
        $this->assertNotNull($ctor);

        $params = $ctor->getParameters();
        $names = array_map(fn ($p) => $p->getName(), $params);
        $this->assertContains('defaultTtl', $names, 'constructor must accept $defaultTtl');

        $defaultTtlParam = null;
        foreach ($params as $p) {
            if ($p->getName() === 'defaultTtl') {
                $defaultTtlParam = $p;
                break;
            }
        }
        $this->assertNotNull($defaultTtlParam);
        $this->assertTrue($defaultTtlParam->isDefaultValueAvailable());
        $this->assertSame(3600, $defaultTtlParam->getDefaultValue());
    }

    #[Test]
    public function classDeclaresDefaultTtlProperty(): void
    {
        $this->assertTrue(
            (new ReflectionClass(ApcuCache::class))->hasProperty('defaultTtl'),
            'ApcuCache must own $defaultTtl property'
        );
    }

    #[Test]
    public function noWriteCallFallsBackToZeroTtl(): void
    {
        $source = $this->source();
        $this->assertDoesNotMatchRegularExpression(
            '/\$ttl\s*\?\?\s*0\b/',
            $source,
            'no write path may silently coerce a null TTL to 0 — always route through $this->defaultTtl'
        );
    }

    #[Test]
    public function writeCallsUseDefaultTtlFallback(): void
    {
        $source = $this->source();
        $matches = preg_match_all('/\$ttl\s*\?\?\s*\$this->defaultTtl\b/', $source);
        // set, setMany, remember, increment = 4 write sites that accept a ?int $ttl.
        $this->assertGreaterThanOrEqual(
            4,
            $matches,
            'all four write paths (set, setMany, remember, increment) must fall back to $this->defaultTtl'
        );
    }
}
