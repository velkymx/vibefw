<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Console\Application as ConsoleApp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModelInspectCommandTest extends TestCase
{
    private string $tempDir;
    private ConsoleApp $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/fw_modelinspect_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . '/app/Models', 0o755, true);

        $this->console = new ConsoleApp($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function commandIsRegistered(): void
    {
        $app = new ConsoleApp(dirname(__DIR__, 2));
        $commands = $app->getCommands();

        $found = false;
        foreach ($commands as $command) {
            if ($command->getName() === 'model:inspect') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found);
    }

    #[Test]
    public function showsModelDetails(): void
    {
        $this->writeModel();

        ob_start();
        $exitCode = $this->console->run(['fw', 'model:inspect', 'Post']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Post', $output);
        $this->assertStringContainsString('posts', $output); // table name
        $this->assertStringContainsString('title', $output); // fillable field
        $this->assertStringContainsString('content', $output);
    }

    #[Test]
    public function showsFillableFields(): void
    {
        $this->writeModel();

        ob_start();
        $this->console->run(['fw', 'model:inspect', 'Post']);
        $output = ob_get_clean();

        $this->assertStringContainsString('Fillable', $output);
        $this->assertStringContainsString('title', $output);
    }

    #[Test]
    public function showsCasts(): void
    {
        $this->writeModel();

        ob_start();
        $this->console->run(['fw', 'model:inspect', 'Post']);
        $output = ob_get_clean();

        $this->assertStringContainsString('Casts', $output);
        $this->assertStringContainsString('published_at', $output);
    }

    #[Test]
    public function showsRelationships(): void
    {
        $this->writeModel();

        ob_start();
        $this->console->run(['fw', 'model:inspect', 'Post']);
        $output = ob_get_clean();

        $this->assertStringContainsString('Relationship', $output);
        $this->assertStringContainsString('author', $output);
        $this->assertStringContainsString('BelongsTo', $output);
    }

    #[Test]
    public function failsForMissingModel(): void
    {
        ob_start();
        $exitCode = $this->console->run(['fw', 'model:inspect', 'NonExistent']);
        ob_get_clean();

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function failsWithoutModelName(): void
    {
        ob_start();
        $exitCode = $this->console->run(['fw', 'model:inspect']);
        ob_get_clean();

        $this->assertSame(1, $exitCode);
    }

    private function writeModel(): void
    {
        file_put_contents($this->tempDir . '/app/Models/Post.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Models;

        use Fw\Model\Model;
        use Fw\Model\Relations\BelongsTo;
        use Fw\Model\Relations\HasMany;

        class Post extends Model
        {
            protected static ?string $table = 'posts';

            protected static array $fillable = [
                'title',
                'content',
                'user_id',
                'published_at',
            ];

            protected static array $casts = [
                'published_at' => 'datetime',
                'is_featured' => 'bool',
            ];

            public function author(): BelongsTo
            {
                return $this->belongsTo(User::class, 'user_id');
            }

            public function comments(): HasMany
            {
                return $this->hasMany(Comment::class);
            }
        }
        PHP);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
