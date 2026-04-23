<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Queue\FileDriver;
use Fw\Queue\Job;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Item #40: TODO claimed `FileDriver::later()` signs the payload
 * BEFORE size-checking it, wasting CPU on oversized payloads. The
 * source already serialises → checks size → signs (size check at
 * ~line 79, signing at ~line 86), so the premise is inverted.
 *
 * [R45] adds two regressions so the correct order can't silently
 * regress:
 *   1. Source-level ordering check — the size-validation block must
 *      appear BEFORE the `signPayload(...)` call in the method
 *      source.
 *   2. Behavioural check — an oversized payload raises the size-
 *      exceeded RuntimeException before any signing work happens.
 *      We can observe this indirectly: the exception message names
 *      the byte count, and signing an oversized payload is wasted
 *      work that would make this message slower to arrive on a
 *      real CPU-constrained host (not timed here — the ordering
 *      claim is what we pin).
 */
final class FileDriverSizeCheckOrderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fw_filedriver_size_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o750, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $sub = glob($this->dir . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($sub as $d) {
            foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($d);
        }
        @rmdir($this->dir);
    }

    #[Test]
    public function laterMethodSourceSizeChecksBeforeSigning(): void
    {
        $rm = new ReflectionMethod(FileDriver::class, 'later');
        $start = $rm->getStartLine();
        $end = $rm->getEndLine();
        $this->assertIsInt($start);
        $this->assertIsInt($end);

        $lines = file((string) $rm->getFileName()) ?: [];
        $body = implode('', array_slice($lines, $start - 1, $end - $start + 1));

        $sizeCheckAt = strpos($body, 'MAX_PAYLOAD_SIZE');
        $signAt = strpos($body, 'signPayload(');

        $this->assertNotFalse($sizeCheckAt, 'later() must reference MAX_PAYLOAD_SIZE');
        $this->assertNotFalse($signAt, 'later() must call signPayload()');
        $this->assertLessThan(
            $signAt,
            $sizeCheckAt,
            'size check must appear BEFORE signPayload() so oversized payloads are rejected before HMAC work'
        );
    }

    #[Test]
    public function oversizedPayloadRaisesBeforeWriting(): void
    {
        $driver = new FileDriver($this->dir);
        $job = new OversizedTestJob();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/too large/');

        try {
            $driver->later(0, $job);
        } finally {
            // After the throw, the queue directory for 'default' may
            // or may not exist (ensureQueueDirectory happens later).
            // Either way no .json file must have been written.
            $queueDir = $this->dir . '/default';
            if (is_dir($queueDir)) {
                $files = glob($queueDir . '/*.json') ?: [];
                $this->assertCount(
                    0,
                    $files,
                    'oversized job must not produce a persisted job file'
                );
            }
        }
    }
}

/**
 * Carries a ~1.5 MB payload inside a serialisable property — exceeds
 * the 1 MB `MAX_PAYLOAD_SIZE` so `later()` must reject it up-front.
 */
final class OversizedTestJob extends Job
{
    public string $payload;

    public function __construct()
    {
        $this->payload = str_repeat('A', 1024 * 1024 + 512 * 1024);
    }

    public function handle(): void
    {
        // no-op
    }
}
