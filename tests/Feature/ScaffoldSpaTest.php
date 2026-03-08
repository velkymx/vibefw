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

    public function test_it_scaffolds_frontend_and_backend(): void
    {
        // 1. Create a dummy app file to check backup
        if (!is_dir($this->tempApp)) mkdir($this->tempApp);
        file_put_contents($this->tempApp . '/OldController.php', '<?php');

        // 2. Run the command (Mocking input for interactive prompts)
        $console = new ConsoleApp(BASE_PATH);
        
        // We need to bypass actual interactive input for automated tests
        // In a real scenario, we'd use a Mock Input helper. 
        // For this framework, we'll manually invoke the logic if the Command class allows it,
        // or test the resulting file system after a simulated run.
        
        $command = new ScaffoldSpaCommand($console);
        $command->setOutput(new \Fw\Console\Output());
        $command->setInput(new \Fw\Console\Input([]));

        // Simulate execution logic (in a real test we'd use a more robust CLI tester)
        $this->runPrivateMethod($command, 'backupAppDirectory');
        $this->runPrivateMethod($command, 'scaffoldBackend');
        $this->runPrivateMethod($command, 'scaffoldFrontend', ['vue', true]);

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

    private function runPrivateMethod(object $object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }
}
