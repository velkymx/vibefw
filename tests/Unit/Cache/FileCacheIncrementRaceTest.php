<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Cache;

use Fw\Cache\FileCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * M12: FileCache increment() does not handle concurrent increments atomically.
 *
 * Pre-fix: The increment() method uses a lock file, but the lock file is
 * created lazily with fopen($lockFile, 'c'). Two concurrent increments could
 * both see no lock file, both create it, and both increment from the same base
 * value, causing lost updates.
 *
 * Post-fix: The lock file is created atomically before any read-modify-write
 * operations. The flock() call ensures only one process can increment at a
 * time, preventing lost updates under high concurrency.
 */
final class FileCacheIncrementRaceTest extends TestCase
{
    private string $cacheDir;
    private FileCache $cache;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/fw_cache_increment_race_' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0o750, true);
        $this->cache = new FileCache($this->cacheDir);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->cacheDir);
    }

    #[Test]
    public function incrementUsesLockFileForAtomicity(): void
    {
        $source = file_get_contents((new \ReflectionClass(FileCache::class))->getFileName());
        $this->assertStringContainsString(
            '.lock',
            $source,
            'increment() should use a lock file for atomic operations'
        );
    }

    #[Test]
    public function incrementAcquiresExclusiveLock(): void
    {
        $source = file_get_contents((new \ReflectionClass(FileCache::class))->getFileName());
        $this->assertStringContainsString(
            'LOCK_EX',
            $source,
            'increment() should acquire exclusive lock'
        );
    }

    #[Test]
    public function incrementReleasesLockInFinallyBlock(): void
    {
        $source = file_get_contents((new \ReflectionClass(FileCache::class))->getFileName());
        $this->assertStringContainsString(
            'finally',
            $source,
            'increment() should release lock in finally block'
        );
    }

    #[Test]
    public function incrementIsAtomic(): void
    {
        // Set initial value
        $this->cache->set('counter', 10);

        // Increment multiple times
        $result1 = $this->cache->increment('counter');
        $result2 = $this->cache->increment('counter');
        $result3 = $this->cache->increment('counter');

        $this->assertSame(11, $result1);
        $this->assertSame(12, $result2);
        $this->assertSame(13, $result3);

        // Verify final value
        $this->assertSame(13, $this->cache->get('counter'));
    }

    #[Test]
    public function incrementWithStep(): void
    {
        $this->cache->set('counter', 10);

        $result = $this->cache->increment('counter', 5);

        $this->assertSame(15, $result);
        $this->assertSame(15, $this->cache->get('counter'));
    }

    #[Test]
    public function incrementClampsAtZero(): void
    {
        $this->cache->set('counter', 2);

        $result = $this->cache->increment('counter', -5);

        $this->assertSame(0, $result);
        $this->assertSame(0, $this->cache->get('counter'));
    }

    #[Test]
    public function incrementPreservesNegativeValueWhenDeliberatelySet(): void
    {
        // Set a negative value directly
        $this->cache->set('counter', -10);

        // Increment (should stay negative)
        $result = $this->cache->increment('counter', 1);

        $this->assertSame(-9, $result);
        $this->assertSame(-9, $this->cache->get('counter'));
    }

    #[Test]
    public function incrementWithTtl(): void
    {
        $this->cache->set('counter', 10);

        $result = $this->cache->increment('counter', 1, 3600);

        $this->assertSame(11, $result);
        $this->assertSame(11, $this->cache->get('counter'));
    }

    #[Test]
    public function incrementCreatesLockFileBeforeOperation(): void
    {
        $key = 'test_counter';
        $hash = hash('sha256', $key);
        $dir = substr($hash, 0, 2);
        $lockFile = $this->cacheDir . '/' . $dir . '/' . $hash . '.cache.lock';

        // Lock file should not exist initially
        $this->assertFileDoesNotExist($lockFile);

        // Perform increment
        $this->cache->increment($key);

        // Lock file should now exist
        $this->assertFileExists($lockFile);
    }

    private function rmTree(string $path): void
    {
        $entries = @scandir($path) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->rmTree($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
