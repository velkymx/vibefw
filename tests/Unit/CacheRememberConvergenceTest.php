<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Cache\Cache;
use Fw\Cache\MemoryCache;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Item #17: Cache::remember() computes the callback on miss and stores.
 * In a worker-mode race, two requests can both miss, both run the
 * callback, and both `set()` — the second write silently clobbers the
 * first. That makes memoised values unstable between concurrent winners
 * when the callback has any non-determinism (UUIDs, timestamps, ordered
 * API results, …).
 *
 * Full stampede prevention needs an interface-level lock primitive
 * (out of scope). What we CAN do at no API cost is add a convergence
 * check: after the callback returns, peek L2 once more; if another
 * request already wrote the key while our callback was running, adopt
 * their value instead of overwriting. Concurrent remember()s then
 * converge on the first stored result.
 */
final class CacheRememberConvergenceTest extends TestCase
{
    #[Test]
    public function rememberAdoptsConcurrentWinnerValue(): void
    {
        $l2 = new MemoryCache();
        $cache = new Cache($l2);

        // Simulate a concurrent winner: during our callback, another
        // request completes first and writes directly to the L2 store.
        $result = $cache->remember('work', function () use ($l2) {
            $l2->set('work', 'from-other-request');
            return 'from-this-request';
        });

        $this->assertSame(
            'from-other-request',
            $result,
            'remember() must re-check L2 after the callback and adopt a concurrent winner — otherwise the last writer clobbers the first.',
        );
        $this->assertSame(
            'from-other-request',
            $cache->get('work'),
            'Subsequent reads must see the adopted winner value, not our own callback output.',
        );
    }

    #[Test]
    public function rememberStoresCallbackValueOnNoConcurrency(): void
    {
        $cache = new Cache(new MemoryCache());

        $calls = 0;
        $result = $cache->remember('once', function () use (&$calls) {
            $calls++;
            return 'computed';
        });

        $this->assertSame('computed', $result);
        $this->assertSame(1, $calls);
        $this->assertSame('computed', $cache->get('once'));

        // Second call is a cache hit — callback must not run again.
        $result2 = $cache->remember('once', function () use (&$calls) {
            $calls++;
            return 'recomputed';
        });

        $this->assertSame('computed', $result2);
        $this->assertSame(1, $calls, 'callback must not run on cache hit');
    }
}
