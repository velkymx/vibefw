<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Cache\FileCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Item #35: `FileCache::increment()` initialises a missing/expired key to 0
 * then unconditionally does `$newValue = $current + $step`. A negative
 * `$step` on a missing key therefore silently produced a negative counter
 * value — a counter that "starts below zero" is almost never what the
 * caller wants and it breaks the contract of increment() as a monotonic
 * counter API (decrement is the sibling operation, not a mode of
 * increment).
 *
 * After [R40] `increment()` clamps the result to 0 when BOTH conditions
 * hold:
 *   - the new value would be negative, AND
 *   - the prior stored value was non-negative (so clamping doesn't
 *     surprise callers who deliberately stored a negative value first).
 *
 * This matches the TODO suggestion verbatim:
 *     if ($newValue < 0 && $current >= 0) { $newValue = 0; }
 *
 * Test matrix:
 *  - missing key + negative step        → clamps to 0
 *  - existing positive counter + big negative step → clamps to 0
 *  - existing counter at 0 + negative step → clamps to 0
 *  - pre-stored negative value + negative step → allowed to go further
 *    negative (don't retroactively rewrite caller state)
 *  - positive step on missing key       → unchanged (returns $step)
 *  - positive step on existing counter  → unchanged (simple addition)
 *  - negative step that still lands ≥ 0 → unchanged (normal decrement)
 */
final class FileCacheIncrementClampTest extends TestCase
{
    private string $dir;
    private FileCache $cache;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fw_filecache_incclamp_' . bin2hex(random_bytes(4));
        $this->cache = new FileCache($this->dir);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    #[Test]
    public function negativeStepOnMissingKeyClampsToZero(): void
    {
        $result = $this->cache->increment('missing', -5);
        $this->assertSame(0, $result, 'missing key + negative step must clamp to 0, not -5');
        $this->assertSame(0, $this->cache->get('missing'));
    }

    #[Test]
    public function negativeStepBelowExistingPositiveCounterClampsToZero(): void
    {
        $this->cache->increment('counter', 3);
        $result = $this->cache->increment('counter', -10);
        $this->assertSame(0, $result, '3 + (-10) crosses zero from positive → clamp');
        $this->assertSame(0, $this->cache->get('counter'));
    }

    #[Test]
    public function negativeStepOnZeroValuedCounterClampsToZero(): void
    {
        $this->cache->set('c', 0);
        $result = $this->cache->increment('c', -1);
        $this->assertSame(0, $result, '0 is non-negative so crossing zero clamps');
    }

    #[Test]
    public function negativeStepOnAlreadyNegativeValueIsNotClamped(): void
    {
        $this->cache->set('n', -2);
        $result = $this->cache->increment('n', -3);
        $this->assertSame(-5, $result, 'prior value already < 0 → caller opted in, no retroactive clamp');
    }

    #[Test]
    public function positiveStepOnMissingKeyReturnsStep(): void
    {
        $result = $this->cache->increment('fresh', 7);
        $this->assertSame(7, $result);
    }

    #[Test]
    public function positiveStepOnExistingCounterAddsNormally(): void
    {
        $this->cache->increment('k', 4);
        $result = $this->cache->increment('k', 3);
        $this->assertSame(7, $result);
    }

    #[Test]
    public function negativeStepThatStaysNonNegativeIsUnchanged(): void
    {
        $this->cache->increment('k', 10);
        $result = $this->cache->increment('k', -3);
        $this->assertSame(7, $result, 'normal decrement that never crosses zero must not clamp');
    }

    #[Test]
    public function defaultStepIsPlusOne(): void
    {
        $this->assertSame(1, $this->cache->increment('k'));
        $this->assertSame(2, $this->cache->increment('k'));
    }

    #[Test]
    public function clampOnExpiredKeyBehavesLikeMissing(): void
    {
        $this->cache->increment('exp', 5, 1);
        sleep(2);
        $result = $this->cache->increment('exp', -3);
        $this->assertSame(
            0,
            $result,
            'expired key is reset to 0, then -3 would go negative → clamp'
        );
    }
}
