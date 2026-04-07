<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Console\Application as ConsoleApp;
use Fw\Console\Commands\AiContextCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiContextCommandTest extends TestCase
{
    private string $tempDir;
    private ConsoleApp $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/fw_aictx_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . '/app/Models', 0o755, true);
        mkdir($this->tempDir . '/app/Controllers', 0o755, true);
        mkdir($this->tempDir . '/app/Requests', 0o755, true);
        mkdir($this->tempDir . '/app/Views/posts', 0o755, true);
        mkdir($this->tempDir . '/database/migrations', 0o755, true);
        mkdir($this->tempDir . '/config', 0o755, true);

        $this->writeFixtures();
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
            if ($command->getName() === 'ai:context') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'The "ai:context" command should be registered');
    }

    #[Test]
    public function dumpsModelForTopic(): void
    {
        ob_start();
        $exitCode = $this->console->run(['fw', 'ai:context', 'post']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Post.php', $output);
        $this->assertStringContainsString('fillable', $output);
    }

    #[Test]
    public function dumpsControllerForTopic(): void
    {
        ob_start();
        $exitCode = $this->console->run(['fw', 'ai:context', 'post']);
        $output = ob_get_clean();

        $this->assertStringContainsString('PostController', $output);
    }

    #[Test]
    public function dumpsRequestForTopic(): void
    {
        ob_start();
        $this->console->run(['fw', 'ai:context', 'post']);
        $output = ob_get_clean();

        $this->assertStringContainsString('StorePostRequest', $output);
    }

    #[Test]
    public function compactModeStripsComments(): void
    {
        ob_start();
        $this->console->run(['fw', 'ai:context', 'post', '--compact']);
        $output = ob_get_clean();

        $this->assertStringContainsString('Post.php', $output);
        // The docblock comment should be stripped in compact mode
        $this->assertStringNotContainsString('This is a docblock', $output);
    }

    #[Test]
    public function jsonModeOutputsValidJson(): void
    {
        ob_start();
        $this->console->run(['fw', 'ai:context', 'post', '--json']);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertNotNull($data, 'Output should be valid JSON');
        $this->assertArrayHasKey('files', $data);
    }

    #[Test]
    public function failsWithoutTopic(): void
    {
        ob_start();
        $exitCode = $this->console->run(['fw', 'ai:context']);
        ob_get_clean();

        $this->assertSame(1, $exitCode);
    }

    private function writeFixtures(): void
    {
        file_put_contents($this->tempDir . '/app/Models/Post.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Models;

        use Fw\Model\Model;

        /**
         * This is a docblock that should be stripped in compact mode.
         */
        class Post extends Model
        {
            protected static ?string $table = 'posts';
            protected static array $fillable = ['title', 'content'];
        }
        PHP);

        file_put_contents($this->tempDir . '/app/Controllers/PostController.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Controllers;

        use Fw\Core\Controller;

        class PostController extends Controller
        {
            public function index(): void {}
        }
        PHP);

        file_put_contents($this->tempDir . '/app/Requests/StorePostRequest.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Requests;

        class StorePostRequest {}
        PHP);

        file_put_contents($this->tempDir . '/app/Views/posts/index.php', '<h1>Posts</h1>');

        file_put_contents($this->tempDir . '/database/migrations/2024_01_01_create_posts_table.php', <<<'PHP'
        <?php
        // Migration for posts
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
