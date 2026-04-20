<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Cache\Cache;
use Fw\Cache\MemoryCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CacheSentinelCollisionTest extends TestCase
{
    #[Test]
    public function getHitsL2EntryThatEqualsCacheInstance(): void
    {
        // Share one L2 store across two Cache facades so the second facade's L1
        // is empty and the lookup has to go through the L2 sentinel path with a
        // stored value (===) identical to the second facade itself.
        $shared = new MemoryCache();
        $seeder = new Cache($shared);
        $reader = new Cache($shared);

        $seeder->set('self', $reader);

        $this->assertSame($reader, $reader->get('self'));
    }

    #[Test]
    public function rememberHitsL2EntryThatEqualsCacheInstance(): void
    {
        $shared = new MemoryCache();
        $seeder = new Cache($shared);
        $reader = new Cache($shared);

        $seeder->set('self', $reader);

        $called = 0;
        $value = $reader->remember('self', function () use (&$called, $reader) {
            $called++;
            return $reader;
        });

        $this->assertSame($reader, $value);
        $this->assertSame(0, $called, 'Callback must not fire on a cache hit even when the cached value === the Cache instance.');
    }

    #[Test]
    public function getReturnsDefaultOnActualMiss(): void
    {
        $cache = new Cache(new MemoryCache());
        $this->assertSame('fallback', $cache->get('absent', 'fallback'));
    }
}
