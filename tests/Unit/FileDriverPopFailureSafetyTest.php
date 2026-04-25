<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Queue\FileDriver;
use Fw\Queue\Job;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Item C4: `FileDriver::pop()` previously assumed `fopen()` and
 * `file_get_contents()` always succeeded.
 *
 *   1. `fopen($lockFile, 'c')` returning `false` (e.g. unwritable
 *      directory, ENFILE/EMFILE, disk full) flowed into
 *      `flock(false, …)` which raises a `TypeError` on PHP 8+ and
 *      kills the worker.
 *
 *   2. `file_get_contents($file)` returning `false` (e.g. transient
 *      EIO, perms changed mid-pop, EBUSY) was decoded as JSON, threw
 *      a `JsonException`, and the catch arm silently `@unlink()`'d
 *      the job file — misclassifying an unreadable file as corrupt
 *      and destroying legitimate work.
 *
 * After the fix `pop()` must:
 *   - Skip a job whose lock file cannot be opened, without crashing.
 *   - Skip a job whose payload cannot be read, without deleting it.
 */
final class FileDriverPopFailureSafetyTest extends TestCase
{
    private string $dir;
    private FileDriver $driver;

    protected function setUp(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('chmod-based file-op failure simulation is no-op for root.');
        }

        $this->dir = sys_get_temp_dir() . '/fw_filedriver_popsafety_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o750, true);
        $this->driver = new FileDriver($this->dir);
    }

    protected function tearDown(): void
    {
        if (!isset($this->dir) || !is_dir($this->dir)) {
            return;
        }

        // Restore perms before recursive cleanup in case a test
        // chmod'd the queue dir to read-only.
        $this->chmodTreeWritable($this->dir);
        $this->rmTree($this->dir);
    }

    #[Test]
    public function popDoesNotCrashWhenLockFileCannotBeOpened(): void
    {
        $job = new DummyPopSafetyJob();
        $this->driver->push($job);

        // Strip write perm on the queue dir so fopen('c') for the
        // brand-new lock file returns false.
        $queueDir = $this->dir . '/default';
        chmod($queueDir, 0o500);

        try {
            $result = $this->driver->pop('default');
        } finally {
            chmod($queueDir, 0o750);
        }

        // Pre-fix this would TypeError on flock(false, …).
        $this->assertNull(
            $result,
            'pop() must return null (skip) when the lock file cannot be opened, not crash.',
        );
    }

    #[Test]
    public function popDoesNotDeleteJobFileOnTransientReadFailure(): void
    {
        $job = new DummyPopSafetyJob();
        $jobId = $this->driver->push($job);

        $jobFile = $this->dir . '/default/' . $jobId . '.json';
        $this->assertFileExists($jobFile, 'sanity: push must persist the job file');

        // Make the file unreadable so file_get_contents() returns
        // false. Pre-fix that hit the JsonException catch arm and
        // @unlink()'d the file. Post-fix the file must survive.
        chmod($jobFile, 0o000);

        try {
            $result = $this->driver->pop('default');
        } finally {
            // Restore perms so tearDown can clean up.
            chmod($jobFile, 0o600);
        }

        $this->assertNull(
            $result,
            'pop() must return null on unreadable payload, not throw and not return a half-built job.',
        );
        $this->assertFileExists(
            $jobFile,
            'unreadable payload must NOT be misclassified as corrupt and deleted; transient read failures must leave the job on disk.',
        );
    }

    private function chmodTreeWritable(string $path): void
    {
        @chmod($path, 0o750);
        $entries = @scandir($path) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->chmodTreeWritable($full);
            } else {
                @chmod($full, 0o600);
            }
        }
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
}

final class DummyPopSafetyJob extends Job
{
    public function handle(): void
    {
        // no-op; payload need not execute, just round-trip through disk.
    }
}
