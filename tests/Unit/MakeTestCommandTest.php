<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Console\Application as ConsoleApp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MakeTestCommandTest extends TestCase
{
    private string $tempDir;
    private ConsoleApp $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/fw_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
        mkdir($this->tempDir . '/app/Models', 0o755, true);
        mkdir($this->tempDir . '/tests/Unit', 0o755, true);
        mkdir($this->tempDir . '/tests/Feature', 0o755, true);

        $this->console = new ConsoleApp($this->tempDir);

        $this->writeModelFile();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function generatesUnitTestFile(): void
    {
        $exitCode = $this->console->run(['fw', 'make:test', 'Post']);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->tempDir . '/tests/Unit/PostModelTest.php');
    }

    #[Test]
    public function generatesFeatureTestFile(): void
    {
        $this->console->run(['fw', 'make:test', 'Post']);

        $this->assertFileExists($this->tempDir . '/tests/Feature/PostCrudTest.php');
    }

    #[Test]
    public function unitTestChecksFillable(): void
    {
        $this->console->run(['fw', 'make:test', 'Post']);

        $content = file_get_contents($this->tempDir . '/tests/Unit/PostModelTest.php');
        $this->assertStringContainsString('fillable', $content);
        $this->assertStringContainsString('title', $content);
    }

    #[Test]
    public function unitTestChecksCasts(): void
    {
        $this->console->run(['fw', 'make:test', 'Post']);

        $content = file_get_contents($this->tempDir . '/tests/Unit/PostModelTest.php');
        $this->assertStringContainsString('casts', $content);
    }

    #[Test]
    public function featureTestCoversIndexRoute(): void
    {
        $this->console->run(['fw', 'make:test', 'Post']);

        $content = file_get_contents($this->tempDir . '/tests/Feature/PostCrudTest.php');
        $this->assertStringContainsString('index', $content);
        $this->assertStringContainsString('posts', $content);
    }

    #[Test]
    public function featureTestCoversStoreRoute(): void
    {
        $this->console->run(['fw', 'make:test', 'Post']);

        $content = file_get_contents($this->tempDir . '/tests/Feature/PostCrudTest.php');
        $this->assertStringContainsString('store', $content);
    }

    #[Test]
    public function failsIfModelDoesNotExist(): void
    {
        $exitCode = $this->console->run(['fw', 'make:test', 'NonExistent']);

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function failsWithoutModelArgument(): void
    {
        $exitCode = $this->console->run(['fw', 'make:test']);

        $this->assertSame(1, $exitCode);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    private function writeModelFile(): void
    {
        $content = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Fw\Model\Model;

class Post extends Model
{
    protected static ?string $table = 'posts';

    protected static array $fillable = [
        'title',
        'content',
        'published_at',
    ];

    protected static array $casts = [
        'published_at' => 'datetime',
    ];
}
PHP;

        file_put_contents($this->tempDir . '/app/Models/Post.php', $content . "\n");
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
