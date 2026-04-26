<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Queue;

use Fw\Queue\FileDriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L1: FileDriver sanitizeQueueName() allows empty string after sanitization.
 *
 * Pre-fix: If the input is all special characters (e.g., `!!!`), `preg_replace`
 * strips them all, leaving an empty string. The code then falls back to `'default'`,
 * but this is not documented behavior.
 *
 * Post-fix: Added PHPDoc comment documenting the fallback behavior to 'default'
 * when the input contains no valid characters.
 */
final class FileDriverSanitizeQueueNameTest extends TestCase
{
    private string $dir;
    private FileDriver $driver;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fw_sanitize_queue_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o750, true);
        $this->driver = new FileDriver($this->dir);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->dir);
    }

    #[Test]
    public function sanitizeQueueNameFallsBackToDefaultForInvalidInput(): void
    {
        $reflection = new \ReflectionClass(FileDriver::class);
        $sanitizeQueueName = $reflection->getMethod('sanitizeQueueName');

        // Test with all special characters
        $result = $sanitizeQueueName->invoke($this->driver, '!!!');
        $this->assertSame('default', $result, 'Should fall back to default for invalid input');
    }

    #[Test]
    public function sanitizeQueueNamePreservesValidCharacters(): void
    {
        $reflection = new \ReflectionClass(FileDriver::class);
        $sanitizeQueueName = $reflection->getMethod('sanitizeQueueName');

        // Test with valid characters
        $result = $sanitizeQueueName->invoke($this->driver, 'my-queue_name123');
        $this->assertSame('my-queue_name123', $result, 'Should preserve valid characters');
    }

    #[Test]
    public function sanitizeQueueNameRemovesSpecialCharacters(): void
    {
        $reflection = new \ReflectionClass(FileDriver::class);
        $sanitizeQueueName = $reflection->getMethod('sanitizeQueueName');

        // Test with mixed valid and invalid characters
        $result = $sanitizeQueueName->invoke($this->driver, 'my@queue#name!');
        $this->assertSame('myqueuename', $result, 'Should remove special characters');
    }

    #[Test]
    public function sanitizeQueueNameLimitsLengthTo64Characters(): void
    {
        $reflection = new \ReflectionClass(FileDriver::class);
        $sanitizeQueueName = $reflection->getMethod('sanitizeQueueName');

        // Test with a very long queue name
        $longName = str_repeat('a', 100);
        $result = $sanitizeQueueName->invoke($this->driver, $longName);
        $this->assertSame(64, strlen($result), 'Should limit to 64 characters');
    }

    #[Test]
    public function sanitizeQueueNameHasPhpDoc(): void
    {
        $source = file_get_contents((new \ReflectionClass(FileDriver::class))->getFileName());
        $this->assertStringContainsString(
            '@return string The sanitized queue name (or \'default\' if invalid)',
            $source,
            'sanitizeQueueName should document the fallback behavior'
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
