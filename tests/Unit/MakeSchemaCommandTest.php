<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Console\Application as ConsoleApp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MakeSchemaCommandTest extends TestCase
{
    private string $tempDir;
    private ConsoleApp $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/fw_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
        mkdir($this->tempDir . '/app/Schemas', 0o755, true);

        $this->console = new ConsoleApp($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function generatesSchemaJsonFile(): void
    {
        $exitCode = $this->console->run(['fw', 'make:schema', 'Post']);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->tempDir . '/app/Schemas/Post.json');
    }

    #[Test]
    public function generatedSchemaIsValidJson(): void
    {
        $this->console->run(['fw', 'make:schema', 'Post']);

        $content = file_get_contents($this->tempDir . '/app/Schemas/Post.json');
        $data = json_decode($content, true);

        $this->assertNotNull($data);
        $this->assertSame('fw://resource-schema', $data['$schema']);
        $this->assertSame('Post', $data['model']);
        $this->assertSame('posts', $data['table']);
        $this->assertFalse($data['api']);
        $this->assertIsArray($data['fields']);
        $this->assertNotEmpty($data['fields']);
    }

    #[Test]
    public function generatedSchemaPassesValidation(): void
    {
        $this->console->run(['fw', 'make:schema', 'Post']);

        $content = file_get_contents($this->tempDir . '/app/Schemas/Post.json');
        $result = \Fw\Schema\ResourceSchemaValidator::validateJson($content);

        $this->assertTrue($result->isOk());
    }

    #[Test]
    public function generatesCorrectTableNameFromModel(): void
    {
        $this->console->run(['fw', 'make:schema', 'BlogPost']);

        $content = file_get_contents($this->tempDir . '/app/Schemas/BlogPost.json');
        $data = json_decode($content, true);

        $this->assertSame('blog_posts', $data['table']);
    }

    #[Test]
    public function failsWithoutModelName(): void
    {
        $exitCode = $this->console->run(['fw', 'make:schema']);

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function failsIfSchemaAlreadyExists(): void
    {
        $this->console->run(['fw', 'make:schema', 'Post']);

        $exitCode = $this->console->run(['fw', 'make:schema', 'Post']);

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function failsWithInvalidModelName(): void
    {
        $exitCode = $this->console->run(['fw', 'make:schema', 'lower_case']);

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function createsSchemaDirectoryIfMissing(): void
    {
        // Remove the schemas directory
        rmdir($this->tempDir . '/app/Schemas');

        $exitCode = $this->console->run(['fw', 'make:schema', 'Post']);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->tempDir . '/app/Schemas/Post.json');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }
}
