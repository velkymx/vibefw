<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Console;

use Fw\Console\Application;
use Fw\Console\Output;
use Fw\Core\Env;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L5: Console Application name/version configurable via config/app.php.
 *
 * Pre-fix: The console application name and version are hardcoded.
 * Applications cannot customize the header shown in command listings.
 *
 * Post-fix: Application name and version are read from config/app.php
 * and can be customized via APP_NAME and APP_VERSION environment variables.
 */
final class ApplicationNameVersionConfigurableTest extends TestCase
{
    private string $originalAppName;
    private string $originalAppVersion;

    protected function setUp(): void
    {
        Env::clear();
    }

    #[Test]
    public function showVersionUsesConfiguredAppName(): void
    {
        $testBasePath = sys_get_temp_dir() . '/fw_test_' . uniqid();
        mkdir($testBasePath . '/config', 0755, true);

        // Create .env file
        file_put_contents($testBasePath . '/.env', "APP_NAME=My Custom App\nAPP_VERSION=1.2.3\n");

        // Create config/app.php file
        file_put_contents(
            $testBasePath . '/config/app.php',
            "<?php\nreturn [\n    'name' => \\Fw\\Core\\Env::string('APP_NAME', 'VibeFw Framework'),\n    'version' => \\Fw\\Core\\Env::string('APP_VERSION', '3.0.0'),\n];\n"
        );

        $output = Output::createBuffer();
        $app = new Application($testBasePath, $output);

        $app->run(['fw', '--version']);

        $outputContent = $output->getBuffer();
        $this->assertStringContainsString('My Custom App', $outputContent);
        $this->assertStringContainsString('v1.2.3', $outputContent);

        // Cleanup
        $this->removeDirectory($testBasePath);
    }

    #[Test]
    public function showHelpUsesConfiguredAppName(): void
    {
        $testBasePath = sys_get_temp_dir() . '/fw_test_' . uniqid();
        mkdir($testBasePath . '/config', 0755, true);

        // Create .env file
        file_put_contents($testBasePath . '/.env', "APP_NAME=Test Application\nAPP_VERSION=2.0.0\n");

        // Create config/app.php file
        file_put_contents(
            $testBasePath . '/config/app.php',
            "<?php\nreturn [\n    'name' => \\Fw\\Core\\Env::string('APP_NAME', 'VibeFw Framework'),\n    'version' => \\Fw\\Core\\Env::string('APP_VERSION', '3.0.0'),\n];\n"
        );

        $output = Output::createBuffer();
        $app = new Application($testBasePath, $output);

        $app->run(['fw', 'list']);

        $outputContent = $output->getBuffer();
        $this->assertStringContainsString('Test Application', $outputContent);
        $this->assertStringContainsString('v2.0.0', $outputContent);

        // Cleanup
        $this->removeDirectory($testBasePath);
    }

    #[Test]
    public function usesDefaultAppNameWhenNotConfigured(): void
    {
        $testBasePath = sys_get_temp_dir() . '/fw_test_' . uniqid();
        mkdir($testBasePath . '/config', 0755, true);

        // Create empty .env file
        file_put_contents($testBasePath . '/.env', '');

        // Create config/app.php file with defaults
        file_put_contents(
            $testBasePath . '/config/app.php',
            "<?php\nreturn [\n    'name' => \\Fw\\Core\\Env::string('APP_NAME', 'VibeFw Framework'),\n    'version' => \\Fw\\Core\\Env::string('APP_VERSION', '3.0.0'),\n];\n"
        );

        $output = Output::createBuffer();
        $app = new Application($testBasePath, $output);

        $app->run(['fw', '--version']);

        $outputContent = $output->getBuffer();
        $this->assertStringContainsString('VibeFw Framework', $outputContent);
        $this->assertStringContainsString('v3.0.0', $outputContent);

        // Cleanup
        $this->removeDirectory($testBasePath);
    }

    private function removeDirectory(string $path): void
    {
        $files = array_diff(scandir($path), ['.', '..']);
        foreach ($files as $file) {
            $filePath = $path . '/' . $file;
            if (is_dir($filePath)) {
                $this->removeDirectory($filePath);
            } else {
                unlink($filePath);
            }
        }
        rmdir($path);
    }
}
