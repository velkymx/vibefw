<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Support\Str;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Item #14: Str::camel/snake/studly all keep a per-process memoization
 * cache. The old eviction logic for snake() counted nested entries
 * (`array_sum(array_map('count', $cache))`) but sliced the OUTER array,
 * so a workload with few source strings and many delimiters would trip
 * the limit and wipe the cache. camel/studly sliced correctly but still
 * duplicated the eviction code inline.
 *
 * These tests lock in:
 *  - all three caches stay bounded under adversarial input
 *  - snake() is not accidentally wiped by multi-delimiter entries
 */
final class StrCacheEvictionTest extends TestCase
{
    private function cache(string $name): array
    {
        $prop = (new ReflectionClass(Str::class))->getProperty($name);
        return $prop->getValue();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Str::flushCache();
    }

    #[Test]
    public function camelCacheStaysBounded(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            Str::camel("some_input_value_number_{$i}");
        }
        $this->assertLessThanOrEqual(512, count($this->cache('camelCache')));
    }

    #[Test]
    public function studlyCacheStaysBounded(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            Str::studly("another_input_value_{$i}");
        }
        $this->assertLessThanOrEqual(512, count($this->cache('studlyCache')));
    }

    #[Test]
    public function snakeCacheStaysBoundedUnderManySourceStrings(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            Str::snake("sourceStringNumber{$i}");
        }
        $this->assertLessThanOrEqual(512, count($this->cache('snakeCache')));
    }

    #[Test]
    public function snakeCacheSurvivesFewKeysManyDelimiters(): void
    {
        // Pathological workload: 10 source strings, 60 delimiters each
        // → 600 nested entries behind 10 outer keys. The old eviction
        // checked nested count (array_sum) but sliced the OUTER array at
        // offset 256 — with only 10 outer keys that slice returns []
        // and the cache is effectively wiped mid-iteration.
        $delimiters = [];
        for ($i = 0; $i < 60; $i++) {
            $delimiters[] = '_' . chr(ord('a') + ($i % 26)) . $i;
        }

        for ($s = 0; $s < 10; $s++) {
            foreach ($delimiters as $d) {
                Str::snake("someSource{$s}", $d);
            }
        }

        // All 10 source strings were cached at some point. Under a
        // correct outer-key bound (size <= 512), every one of them
        // should still be present at the end.
        $cache = $this->cache('snakeCache');
        $this->assertCount(
            10,
            $cache,
            'Expected 10 outer keys to survive — the old eviction wipes them all when nested count trips 512.',
        );
    }
}
