<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Queue;

use Fw\Queue\FileDriver;
use Fw\Queue\Job;
use Fw\Queue\JobInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Item C5 — `unserialize()` allowed_classes contract.
 *
 * Pre-fix the driver defaulted `allowed_classes` to `true`, which lets
 * any class with a `__destruct`/`__wakeup` gadget instantiate during
 * deserialization. The HMAC stops tampering, but if the queue dir or
 * HMAC key ever leaks, that wide-open allowlist becomes an RCE
 * primitive.
 *
 * Post-fix:
 *   - default allowlist is `[]` (fail-closed: pop() throws until configured)
 *   - `allowClasses()` rejects wildcards and any class not implementing
 *     `JobInterface`
 *   - real Job subclasses still round-trip through push/pop unchanged
 */
final class FileDriverAllowlistTest extends TestCase
{
    private string $dir;
    private FileDriver $driver;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fw_filedriver_allowlist_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o750, true);
        $this->driver = new FileDriver($this->dir);
    }

    protected function tearDown(): void
    {
        if (!isset($this->dir) || !is_dir($this->dir)) {
            return;
        }
        $this->rmTree($this->dir);
    }

    #[Test]
    public function popThrowsWhenAllowlistIsEmpty(): void
    {
        $this->driver->push(new AllowlistDummyJob());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/allowlist|allow_classes|allowed_classes/i');

        $this->driver->pop('default');
    }

    #[Test]
    public function allowClassesRejectsWildcardString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->driver->allowClasses(['*']);
    }

    #[Test]
    public function allowClassesRejectsClassThatDoesNotImplementJobInterface(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->driver->allowClasses([stdClass::class]);
    }

    #[Test]
    public function allowClassesRejectsUnknownClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->driver->allowClasses(['Fw\\Tests\\NotARealClass']);
    }

    #[Test]
    public function allowedJobClassRoundTripsThroughPushPop(): void
    {
        $this->driver->allowClasses([AllowlistDummyJob::class]);

        $id = $this->driver->push(new AllowlistDummyJob());

        $reserved = $this->driver->pop('default');

        $this->assertNotNull($reserved, 'configured allowlist must allow pop() of a permitted Job class');
        $this->assertSame($id, $reserved['id']);
        $this->assertInstanceOf(AllowlistDummyJob::class, $reserved['job']);
        $this->assertInstanceOf(JobInterface::class, $reserved['job']);
    }

    #[Test]
    public function allowClassesIsChainable(): void
    {
        $this->assertSame(
            $this->driver,
            $this->driver->allowClasses([AllowlistDummyJob::class]),
            'allowClasses() must remain fluent for provider/wiring code',
        );
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

final class AllowlistDummyJob extends Job
{
    public function handle(): void
    {
        // no-op
    }
}
