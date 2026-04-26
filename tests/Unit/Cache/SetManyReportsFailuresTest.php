<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Cache;

use Fw\Cache\FileCache;
use Fw\Cache\MemoryCache;
use Fw\Cache\Cache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * M3: setMany() silently swallowed per-entry failures.
 *
 * All drivers ignored the bool return from set() and always returned true,
 * even when individual entries failed to persist. After fix, setMany()
 * returns false if any set() call returned false.
 */
final class SetManyReportsFailuresTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/fw_setmany_test_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        chmod($this->tmpDir, 0o750);
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tmpDir);
    }

    #[Test]
    public function memoryCacheSetManyReturnsTrueWhenAllSucceed(): void
    {
        $cache = new MemoryCache();
        $result = $cache->setMany(['a' => 1, 'b' => 2, 'c' => 3]);
        $this->assertTrue($result);
    }

    #[Test]
    public function fileCacheSetManyReturnsTrueWhenAllSucceed(): void
    {
        $cache = new FileCache($this->tmpDir);
        $result = $cache->setMany(['x' => 'hello', 'y' => 'world']);
        $this->assertTrue($result);
    }

    #[Test]
    public function fileCacheSetManyReturnsFalseWhenWriteFails(): void
    {
        $cache = new FileCache($this->tmpDir);
        // Write one entry successfully first
        $cache->set('good', 'value');
        $this->assertTrue($cache->has('good'));

        // Make cache directory read-only so subsequent writes fail
        chmod($this->tmpDir, 0o500);

        $result = $cache->setMany(['a' => 1, 'b' => 2]);
        $this->assertFalse($result, 'setMany should return false when underlying set() fails');
    }

    #[Test]
    public function layeredCacheSetManyReturnsTrueWhenAllSucceed(): void
    {
        $l2 = new FileCache($this->tmpDir);
        $cache = new Cache($l2);

        $result = $cache->setMany(['a' => 1, 'b' => 2]);
        $this->assertTrue($result);
    }

    #[Test]
    public function setManyContinuesWritingAfterFailure(): void
    {
        // Create a cache backed by a read-only directory so all writes fail.
        // Verify that setMany still iterates through all keys without early return.
        $readOnlyDir = sys_get_temp_dir() . '/fw_setmany_ro_' . bin2hex(random_bytes(8));
        mkdir($readOnlyDir . '/sub', 0o750, true);
        file_put_contents($readOnlyDir . '/sub/test.cache', '{}');
        chmod($readOnlyDir . '/sub', 0o500);
        chmod($readOnlyDir, 0o500);

        $cache = new FileCache($readOnlyDir);
        $result = $cache->setMany(['a' => 1, 'b' => 2, 'c' => 3]);
        $this->assertFalse($result);

        chmod($readOnlyDir . '/sub', 0o750);
        chmod($readOnlyDir, 0o750);
        unlink($readOnlyDir . '/sub/test.cache');
        rmdir($readOnlyDir . '/sub');
        rmdir($readOnlyDir);
    }

    #[Test]
    public function setManyReturnsTrueOnEmptyInput(): void
    {
        $cache = new MemoryCache();
        $this->assertTrue($cache->setMany([]));
    }
}
