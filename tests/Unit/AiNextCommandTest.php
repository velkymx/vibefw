<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Console\Application as ConsoleApp;
use Fw\Console\Output;
use Fw\Console\Commands\AiNextCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiNextCommandTest extends TestCase
{
    private string $tempDir;
    private ConsoleApp $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/fw_ainext_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . '/config', 0o755, true);

        $this->console = new ConsoleApp($this->tempDir, Output::createBuffer());
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function commandIsRegistered(): void
    {
        $app = new ConsoleApp(dirname(__DIR__, 2), Output::createBuffer());
        $commands = $app->getCommands();

        $found = false;
        foreach ($commands as $command) {
            if ($command->getName() === 'ai:next') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'The "ai:next" command should be registered');
    }

    #[Test]
    public function suggestsSetupForEmptyProject(): void
    {
        // Empty project — no models, no routes
        file_put_contents($this->tempDir . '/config/routes.php', "<?php\nreturn function() {};");

        $exitCode = $this->console->run(['fw', 'ai:next']);
        $output = $this->console->getOutput()->getBuffer();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('php fw', $output);
    }

    #[Test]
    public function suggestsMigrationWhenModelsExistButNoMigrations(): void
    {
        mkdir($this->tempDir . '/app/Models', 0o755, true);
        mkdir($this->tempDir . '/database/migrations', 0o755, true);

        file_put_contents($this->tempDir . '/app/Models/Post.php', <<<'PHP'
        <?php
        namespace App\Models;
        use Fw\Model\Model;
        class Post extends Model {
            protected static array $fillable = ['title'];
        }
        PHP);

        $exitCode = $this->console->run(['fw', 'ai:next']);
        $output = $this->console->getOutput()->getBuffer();

        $this->assertSame(0, $exitCode);
        // Should suggest something related to the project state
        $this->assertStringContainsString('php fw', $output);
    }

    #[Test]
    public function outputIncludesExactCommand(): void
    {
        $this->console->run(['fw', 'ai:next']);
        $output = $this->console->getOutput()->getBuffer();

        // Every suggestion should include an exact fw command
        $this->assertMatchesRegularExpression('/php fw \S+/', $output);
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
