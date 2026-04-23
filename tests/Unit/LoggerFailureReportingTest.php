<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Log\Logger;
use Fw\Log\LogLevel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Item #28: `reportWriteFailures` was a sticky bool that flipped to
 * false after the first write failure and never recovered, even if
 * the underlying filesystem issue (disk full, bad mount, rotated
 * perms) resolved. Long-running workers would silently swallow every
 * subsequent log write failure.
 *
 * After [R33]:
 *  - Write failures are reported up to a fixed cap per consecutive run.
 *  - A single successful write resets the counter so reporting resumes.
 *  - A settable `failureReporter` callable replaces the hardcoded
 *    stderr/error_log fallback, mostly so these tests don't spam the
 *    actual PHPUnit runner's stderr, but it's also a useful DI seam.
 */
final class LoggerFailureReportingTest extends TestCase
{
    /** Temp file path (NOT a directory) — forces file_put_contents to fail. */
    private string $brokenDir;

    /** Temp directory — writes succeed here. */
    private string $workingDir;

    protected function setUp(): void
    {
        // A *file* where Logger expects a directory. file_put_contents on
        // "<file>/fw-YYYY-MM-DD.log" fails deterministically.
        $this->brokenDir = (string) tempnam(sys_get_temp_dir(), 'loggerbrk');

        $this->workingDir = sys_get_temp_dir() . '/loggerok_' . uniqid('', true);
        mkdir($this->workingDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->brokenDir);
        foreach (glob($this->workingDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->workingDir);
    }

    #[Test]
    public function reportsCapAtThreeConsecutiveFailures(): void
    {
        $reported = [];
        $logger = new Logger($this->brokenDir);
        $logger->setFailureReporter(function (string $msg) use (&$reported): void {
            $reported[] = $msg;
        });

        for ($i = 0; $i < 5; $i++) {
            $logger->info("attempt-{$i}");
        }

        $this->assertCount(3, $reported, 'reports must cap at MAX_REPORTED_FAILURES');
    }

    #[Test]
    public function counterResetsAfterSuccessfulWrite(): void
    {
        $logger = new Logger($this->brokenDir);
        $logger->setFailureReporter(fn () => null);

        $logger->info('fail-1');
        $logger->info('fail-2');
        $this->assertSame(2, $this->counterOf($logger));

        $this->setPath($logger, $this->workingDir);
        $logger->info('ok');

        $this->assertSame(0, $this->counterOf($logger));
    }

    #[Test]
    public function reportingResumesAfterRecovery(): void
    {
        $reported = [];
        $logger = new Logger($this->brokenDir);
        $logger->setFailureReporter(function (string $msg) use (&$reported): void {
            $reported[] = $msg;
        });

        // burn through the cap
        for ($i = 0; $i < 5; $i++) {
            $logger->info("pre-{$i}");
        }
        $this->assertCount(3, $reported);

        // recover: one successful write
        $this->setPath($logger, $this->workingDir);
        $logger->info('recovered');

        // break again — reporting must pick back up
        $this->setPath($logger, $this->brokenDir);
        $logger->info('post-1');
        $logger->info('post-2');

        $this->assertCount(5, $reported, 'reporting must resume after reset');
    }

    #[Test]
    public function singleFailureStillReported(): void
    {
        $reported = [];
        $logger = new Logger($this->brokenDir);
        $logger->setFailureReporter(function (string $msg) use (&$reported): void {
            $reported[] = $msg;
        });

        $logger->error('one-off');

        $this->assertCount(1, $reported);
        $this->assertStringContainsString('[LOGGER ERROR]', $reported[0]);
        $this->assertStringContainsString('[ORIGINAL MESSAGE]', $reported[0]);
    }

    #[Test]
    public function successOnlyCallsNothingIntoReporter(): void
    {
        $reported = [];
        $logger = new Logger($this->workingDir);
        $logger->setFailureReporter(function (string $msg) use (&$reported): void {
            $reported[] = $msg;
        });

        $logger->warning('all fine');

        $this->assertSame([], $reported);
        $this->assertSame(0, $this->counterOf($logger));
    }

    private function counterOf(Logger $logger): int
    {
        $rc = new ReflectionClass(Logger::class);
        $prop = $rc->getProperty('consecutiveWriteFailures');
        return (int) $prop->getValue($logger);
    }

    private function setPath(Logger $logger, string $path): void
    {
        $rc = new ReflectionClass(Logger::class);
        $prop = $rc->getProperty('path');
        $prop->setValue($logger, $path);
    }
}
