<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Cache\FileCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * B-NEW-05 / E-NEW-09: FileCache uses isset() to validate stored data,
 * which returns false for NULL values.  Every no-TTL entry and every
 * explicitly-null value is immediately discarded on first read.
 */
final class FileCacheTest extends TestCase
{
    private string $dir;
    private FileCache $cache;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fw_filecache_test_' . bin2hex(random_bytes(4));
        $this->cache = new FileCache($this->dir);
    }

    protected function tearDown(): void
    {
        // Recursive delete of temp dir
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    // -------------------------------------------------------------------------
    // Basic round-trip
    // -------------------------------------------------------------------------

    #[Test]
    public function setAndGetStringValue(): void
    {
        $this->cache->set('k', 'hello');
        $this->assertSame('hello', $this->cache->get('k'));
    }

    #[Test]
    public function setAndGetIntegerValue(): void
    {
        $this->cache->set('num', 42);
        $this->assertSame(42, $this->cache->get('num'));
    }

    // -------------------------------------------------------------------------
    // No-TTL entries (expires stored as null)
    // -------------------------------------------------------------------------

    #[Test]
    public function noTtlEntryPersistsAcrossMultipleGets(): void
    {
        // B-NEW-05: set() stores expires=null; get() must NOT treat that as invalid format.
        $this->cache->set('persist', 'value');

        $this->assertSame('value', $this->cache->get('persist'), 'first read');
        $this->assertSame('value', $this->cache->get('persist'), 'second read');
        $this->assertSame('value', $this->cache->get('persist'), 'third read');
    }

    #[Test]
    public function noTtlEntryHasReturnsTrue(): void
    {
        $this->cache->set('present', 'yes');

        $this->assertTrue($this->cache->has('present'));
        // Must still be true after has() (which internally calls get())
        $this->assertTrue($this->cache->has('present'));
    }

    // -------------------------------------------------------------------------
    // Null value caching
    // -------------------------------------------------------------------------

    #[Test]
    public function nullValueCanBeStoredAndRetrieved(): void
    {
        // B-NEW-05: isset($data['value']) returns false for null — must use array_key_exists.
        $this->cache->set('nullkey', null);

        $this->assertNull($this->cache->get('nullkey', 'fallback'));
    }

    #[Test]
    public function hasReturnsTrueForCachedNullValue(): void
    {
        $this->cache->set('nullkey', null);

        $this->assertTrue($this->cache->has('nullkey'));
    }

    #[Test]
    public function hasReturnsFalseForMissingKey(): void
    {
        $this->assertFalse($this->cache->has('nosuchkey'));
    }

    #[Test]
    public function getReturnsDefaultForMissingKey(): void
    {
        $this->assertSame('default', $this->cache->get('missing', 'default'));
    }

    // -------------------------------------------------------------------------
    // remember() with null return
    // -------------------------------------------------------------------------

    #[Test]
    public function rememberCachesNullReturnValue(): void
    {
        $calls = 0;
        $fn    = function () use (&$calls): ?string {
            $calls++;
            return null;
        };

        $r1 = $this->cache->remember('rkey', $fn);
        $r2 = $this->cache->remember('rkey', $fn);

        $this->assertNull($r1);
        $this->assertNull($r2);
        $this->assertSame(1, $calls, 'callback must be called only once');
    }

    #[Test]
    public function rememberReturnsExistingValueWithoutCallingCallback(): void
    {
        $calls = 0;
        $this->cache->set('existing', 'stored');

        $result = $this->cache->remember('existing', function () use (&$calls) {
            $calls++;
            return 'fresh';
        });

        $this->assertSame('stored', $result);
        $this->assertSame(0, $calls, 'callback must not be called for existing key');
    }

    // -------------------------------------------------------------------------
    // TTL expiry
    // -------------------------------------------------------------------------

    #[Test]
    public function expiredEntryReturnsDefault(): void
    {
        $this->cache->set('exp', 'gone', -1); // already expired
        $this->assertSame('fallback', $this->cache->get('exp', 'fallback'));
    }

    #[Test]
    public function expiredEntryHasReturnsFalse(): void
    {
        $this->cache->set('exp', 'gone', -1);
        $this->assertFalse($this->cache->has('exp'));
    }

    // -------------------------------------------------------------------------
    // delete / clear
    // -------------------------------------------------------------------------

    #[Test]
    public function deletedKeyReturnsDefault(): void
    {
        $this->cache->set('del', 'bye');
        $this->cache->delete('del');
        $this->assertSame('nope', $this->cache->get('del', 'nope'));
    }

    #[Test]
    public function clearRemovesAllEntries(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        $this->cache->clear();

        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->has('b'));
    }
}
