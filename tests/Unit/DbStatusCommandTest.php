<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Console\Application as ConsoleApp;
use Fw\Console\Output;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DbStatusCommandTest extends TestCase
{
    private string $tempDir;
    private ConsoleApp $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/fw_dbstatus_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . '/config', 0o755, true);
        mkdir($this->tempDir . '/database/migrations', 0o755, true);

        // Write a minimal .env for SQLite
        file_put_contents($this->tempDir . '/.env', "DB_DRIVER=sqlite\nDB_DATABASE=:memory:\n");

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
            if ($command->getName() === 'db:status') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found);
    }

    #[Test]
    public function showsDriverInfo(): void
    {
        $exitCode = $this->console->run(['fw', 'db:status']);
        $output = $this->console->getOutput()->getBuffer();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Driver', $output);
    }

    #[Test]
    public function showsMigrationCount(): void
    {
        $this->writeMigration();

        $this->console->run(['fw', 'db:status']);
        $output = $this->console->getOutput()->getBuffer();

        $this->assertStringContainsString('Migration', $output);
    }

    private function writeMigration(): void
    {
        file_put_contents($this->tempDir . '/database/migrations/0001_create_users_table.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        use Fw\Database\Migration\Migration;
        use Fw\Database\Migration\Blueprint;
        return new class extends Migration {
            public function up(): void {}
            public function down(): void {}
        };
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
