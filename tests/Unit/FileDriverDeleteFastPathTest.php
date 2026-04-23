<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Queue\FileDriver;
use Fw\Queue\Job;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Item #41: `FileDriver::delete()` / `release()` iterated every
 * queue directory on disk to find the job file, because the
 * driver's `generateId()` produced an opaque random ID that carried
 * no queue information. That made deletion O(number of queues) per
 * call.
 *
 * [R46] encodes the sanitised queue name into the generated jobId
 * as `{queue}@{ts}_{rand}`. `delete()` and `release()` now extract
 * the queue prefix and hit a single file path directly; if the
 * prefix can't be parsed (legacy jobs pushed before this commit),
 * the old O(n) fallback runs so nothing currently on disk breaks.
 *
 * Tests verify:
 *   - round-trip: push → ID is the `{queue}@…` shape
 *   - multi-queue isolation: deleting job A from queue X does not
 *     touch job B in queue Y
 *   - fast-path does not require the OTHER queue directory to
 *     exist (documents the O(1) claim structurally — the call
 *     succeeds even when other queues are absent)
 *   - legacy-shape ID (no `@`) still deletable through the fallback
 *   - release() benefits from the same fast path
 */
final class FileDriverDeleteFastPathTest extends TestCase
{
    private string $dir;
    private FileDriver $driver;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fw_filedriver_delete_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o750, true);
        $this->driver = new FileDriver($this->dir);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->dir);
    }

    private function rmTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path) ?: [];
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

    #[Test]
    public function generatedJobIdEncodesQueueNameAsPrefix(): void
    {
        $job = new DummyFileDriverJob();
        $id = $this->driver->push($job);

        $this->assertMatchesRegularExpression(
            '/^default@\d+_[0-9a-f]{16}$/',
            $id,
            'default-queue jobId must carry the queue prefix + @ separator'
        );
    }

    #[Test]
    public function customQueueNameIsPreservedInJobId(): void
    {
        $job = (new DummyFileDriverJob())->onQueue('emails');
        $id = $this->driver->push($job);

        $this->assertStringStartsWith('emails@', $id);
    }

    #[Test]
    public function deletingJobRemovesOnlyThatFile(): void
    {
        $a = (new DummyFileDriverJob())->onQueue('alpha');
        $b = (new DummyFileDriverJob())->onQueue('bravo');

        $idA = $this->driver->push($a);
        $idB = $this->driver->push($b);

        $this->assertTrue($this->driver->delete($idA));

        $this->assertSame(0, $this->driver->size('alpha'));
        $this->assertSame(1, $this->driver->size('bravo'), 'unrelated queue must be untouched');
        $this->assertStringStartsWith('bravo@', $idB);
    }

    #[Test]
    public function deleteFastPathSucceedsWithoutOtherQueueDirs(): void
    {
        $job = (new DummyFileDriverJob())->onQueue('only');
        $id = $this->driver->push($job);

        // Sanity: only the 'only' queue directory exists.
        $dirs = glob($this->dir . '/*', GLOB_ONLYDIR) ?: [];
        $basenames = array_map('basename', $dirs);
        $this->assertSame(['only'], array_values($basenames));

        $this->assertTrue(
            $this->driver->delete($id),
            'fast-path delete must succeed without scanning nonexistent queue dirs'
        );
    }

    #[Test]
    public function legacyIdWithoutPrefixStillDeletesViaFallback(): void
    {
        // Simulate a job file written with a legacy non-prefixed ID
        // (the shape generateId() used before this commit).
        $queueDir = $this->dir . '/default';
        mkdir($queueDir, 0o750, true);
        $legacyId = (string) time() . '_' . bin2hex(random_bytes(8));
        $file = $queueDir . '/' . $legacyId . '.json';
        file_put_contents($file, json_encode([
            'id' => $legacyId,
            'queue' => 'default',
            'job' => 'sig.ZmFrZQ==',
            'attempts' => 0,
            'available_at' => time(),
            'created_at' => time(),
            'reserved_at' => null,
        ]));

        $this->assertTrue($this->driver->delete($legacyId));
        $this->assertFileDoesNotExist($file);
    }

    #[Test]
    public function releaseUsesQueuePrefixFastPath(): void
    {
        $job = (new DummyFileDriverJob())->onQueue('retryq');
        $id = $this->driver->push($job);

        $this->assertTrue(
            $this->driver->release($id, 30),
            'release() must resolve via the queue prefix without scanning every queue'
        );

        $this->assertSame(1, $this->driver->size('retryq'));
    }

    #[Test]
    public function malformedPrefixFallsBackToScan(): void
    {
        // jobId contains `@` but the prefix isn't a valid queue name
        // shape — must not try to use it as a directory and must
        // fall back to the scan path (returning false when nothing
        // matches).
        $this->assertFalse($this->driver->delete('../bad@123_xyz'));
    }
}

final class DummyFileDriverJob extends Job
{
    public function handle(): void
    {
        // no-op
    }
}
