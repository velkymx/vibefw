<?php

declare(strict_types=1);

namespace Fw\Tests\Feature;

use Fw\Tests\TestCase;
use Fw\Console\Application as ConsoleApp;
use Fw\Console\Commands\ScaffoldSpaCommand;

final class ScaffoldSpaTest extends TestCase
{
    private string $tempApp;
    private string $tempFrontend;

    private ?string $backupDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempApp = BASE_PATH . '/app';
        $this->tempFrontend = BASE_PATH . '/frontend';

        // Ensure clean state for tests
        if (is_dir($this->tempFrontend)) {
            $this->deleteDir($this->tempFrontend);
        }
    }

    protected function tearDown(): void
    {
        // Restore the original app directory from the most recent backup
        $backups = glob(BASE_PATH . '/storage/backups/app_*');
        if (!empty($backups)) {
            sort($backups);
            $latestBackup = end($backups);

            // Remove the scaffolded app directory
            if (is_dir($this->tempApp)) {
                $this->deleteDir($this->tempApp);
            }

            // Restore from backup
            rename($latestBackup, $this->tempApp);
        }

        // Clean up scaffolded frontend
        if (is_dir($this->tempFrontend)) {
            $this->deleteDir($this->tempFrontend);
        }

        parent::tearDown();
    }

    public function test_it_scaffolds_frontend_and_backend(): void
    {
        // 1. Create a dummy app file to check backup
        if (!is_dir($this->tempApp)) {
            mkdir($this->tempApp);
        }
        file_put_contents($this->tempApp . '/OldController.php', '<?php');

        // 2. Run the command
        $console = new ConsoleApp(BASE_PATH);

        $command = new ScaffoldSpaCommand($console);
        $command->setOutput(new \Fw\Console\Output());
        $command->setInput(new \Fw\Console\Input([]));

        // Capture output to prevent "risky test" warning
        ob_start();
        $this->runPrivateMethod($command, 'backupAppDirectory');
        $this->runPrivateMethod($command, 'scaffoldBackend');
        $this->runPrivateMethod($command, 'scaffoldFrontend', ['vue', true]);
        ob_end_clean();

        // 3. Assertions
        $this->assertDirectoryExists($this->tempFrontend);
        $this->assertFileExists($this->tempFrontend . '/package.json');
        $this->assertFileExists($this->tempFrontend . '/vite.config.ts');
        
        // Check if API controller was created
        $this->assertFileExists($this->tempApp . '/Controllers/Api/Auth/LoginController.php');
        
        // Check if backup exists
        $backups = glob(BASE_PATH . '/storage/backups/app_*');
        $this->assertNotEmpty($backups, 'Backup directory should have been created');
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->deleteDir("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }

    private function runPrivateMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new \ReflectionClass($object::class);
        $method = $reflection->getMethod($methodName);

        return $method->invokeArgs($object, $parameters);
    }
}
