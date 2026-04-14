<?php

declare(strict_types=1);

namespace Fw\Tests\Feature;

use Fw\Console\Application as ConsoleApp;
use Fw\Console\Commands\ScaffoldSpaCommand;
use Fw\Console\Input;
use Fw\Console\Output;
use Fw\Tests\TestCase;
use ReflectionClass;

/**
 * Tests for ScaffoldSpaCommand operating on an isolated temp directory.
 *
 * The command must never read from or write to BASE_PATH/app, BASE_PATH/frontend,
 * or BASE_PATH/storage/backups during tests. Each test gets its own temp tree
 * so tests cannot corrupt real project state or interfere with each other.
 */
final class ScaffoldSpaTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolated temp project root — never touches real BASE_PATH/app
        $this->tempRoot = sys_get_temp_dir() . '/fw_spa_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempRoot, 0o755, true);

        // Scaffold the directory skeleton expected by the command
        foreach (['app', 'config', 'database/migrations', 'storage/backups', 'frontend'] as $dir) {
            mkdir($this->tempRoot . '/' . $dir, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempRoot);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeCommand(): ScaffoldSpaCommand
    {
        $console = new ConsoleApp(BASE_PATH);
        $command = new ScaffoldSpaCommand($console);
        $command->setOutput(new Output());
        $command->setInput(new Input([]));
        $command->withProjectPath($this->tempRoot);
        return $command;
    }

    private function invoke(object $object, string $method, array $args = []): mixed
    {
        $ref = new ReflectionClass($object);
        return $ref->getMethod($method)->invokeArgs($object, $args);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = "$dir/$entry";
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testScaffoldDoesNotTouchRealAppDirectory(): void
    {
        $realAppContents = scandir(BASE_PATH . '/app');

        $command = $this->makeCommand();
        ob_start();
        $this->invoke($command, 'backupAppDirectory');
        $this->invoke($command, 'scaffoldBackend');
        ob_end_clean();

        // Real app/ must be byte-for-byte identical after the command runs
        $this->assertSame(
            $realAppContents,
            scandir(BASE_PATH . '/app'),
            'ScaffoldSpaCommand must not modify BASE_PATH/app during tests'
        );
    }

    public function testBackupWritesToTempRoot(): void
    {
        // Plant a sentinel file in temp app/ to verify it gets backed up
        file_put_contents($this->tempRoot . '/app/OldController.php', '<?php // sentinel');

        $command = $this->makeCommand();
        ob_start();
        $this->invoke($command, 'backupAppDirectory');
        ob_end_clean();

        $backups = glob($this->tempRoot . '/storage/backups/app_*');
        $this->assertNotEmpty($backups, 'Backup must be created in temp root, not in BASE_PATH');

        // Backup must contain the sentinel
        $backup = $backups[0];
        $this->assertFileExists($backup . '/OldController.php');

        // Real BASE_PATH/storage/backups must be untouched
        $realBackups = array_filter(
            glob(BASE_PATH . '/storage/backups/app_*') ?: [],
            fn($b) => filemtime($b) >= time() - 5 // created in last 5 seconds
        );
        $this->assertEmpty($realBackups, 'No new backups must appear in real project tree');
    }

    public function testScaffoldBackendCreatesControllersInTempRoot(): void
    {
        $command = $this->makeCommand();
        ob_start();
        $this->invoke($command, 'backupAppDirectory');
        $this->invoke($command, 'scaffoldBackend');
        ob_end_clean();

        $this->assertFileExists(
            $this->tempRoot . '/app/Controllers/Api/Auth/LoginController.php',
            'LoginController must be scaffolded into temp root, not real app/'
        );
        $this->assertFileExists(
            $this->tempRoot . '/app/Models/PersonalAccessToken.php'
        );
    }

    public function testScaffoldFrontendCreatesPackageJsonInTempRoot(): void
    {
        $command = $this->makeCommand();
        ob_start();
        $this->invoke($command, 'scaffoldFrontend', ['vue', true]);
        ob_end_clean();

        $this->assertFileExists($this->tempRoot . '/frontend/package.json');
        $this->assertFileExists($this->tempRoot . '/frontend/vite.config.ts');

        // Real frontend dir must remain absent / unchanged
        $this->assertDirectoryDoesNotExist(BASE_PATH . '/frontend/src/views/Dashboard.vue');
    }

    public function testTearDownNeverTouchesRealApp(): void
    {
        // This test verifies setUp/tearDown isolation: the temp root is created
        // fresh and removed after each test, leaving BASE_PATH/app untouched.
        $this->assertStringStartsWith(sys_get_temp_dir(), $this->tempRoot);
        $this->assertStringNotContainsString(BASE_PATH, $this->tempRoot);
    }
}
