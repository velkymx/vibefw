<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Console\Application as ConsoleApp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TestForCommandTest extends TestCase
{
    private string $tempDir;
    private ConsoleApp $console;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/fw_testfor_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . '/tests/Unit', 0o755, true);
        mkdir($this->tempDir . '/tests/Feature', 0o755, true);

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
            if ($command->getName() === 'test:for') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found);
    }

    #[Test]
    public function findsTestsByTopic(): void
    {
        $this->writeTestFiles();

        ob_start();
        $exitCode = $this->console->run(['fw', 'test:for', 'post']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('PostTest', $output);
        $this->assertStringNotContainsString('UserTest', $output);
    }

    #[Test]
    public function showsNoMatchesMessage(): void
    {
        $this->writeTestFiles();

        ob_start();
        $exitCode = $this->console->run(['fw', 'test:for', 'nonexistent']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No test', $output);
    }

    #[Test]
    public function failsWithoutTopic(): void
    {
        ob_start();
        $exitCode = $this->console->run(['fw', 'test:for']);
        ob_get_clean();

        $this->assertSame(1, $exitCode);
    }

    private function writeTestFiles(): void
    {
        file_put_contents($this->tempDir . '/tests/Unit/PostTest.php', '<?php // PostTest');
        file_put_contents($this->tempDir . '/tests/Unit/PostControllerTest.php', '<?php // PostControllerTest');
        file_put_contents($this->tempDir . '/tests/Feature/PostFeatureTest.php', '<?php // PostFeatureTest');
        file_put_contents($this->tempDir . '/tests/Unit/UserTest.php', '<?php // UserTest');
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
