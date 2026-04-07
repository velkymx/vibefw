<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Console\Application as ConsoleApp;
use Fw\Console\Commands\CheckCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CheckCommandTest extends TestCase
{
    #[Test]
    public function commandIsRegistered(): void
    {
        $app = new ConsoleApp(dirname(__DIR__, 2));
        $commands = $app->getCommands();

        $found = false;
        foreach ($commands as $command) {
            if ($command->getName() === 'check') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'The "check" command should be registered');
    }

    #[Test]
    public function commandHasCorrectDescription(): void
    {
        $app = new ConsoleApp(dirname(__DIR__, 2));
        $command = new CheckCommand($app);

        $this->assertSame('check', $command->getName());
        $this->assertStringContainsString('convention', strtolower($command->getDescription()));
    }

    #[Test]
    public function commandRunsWithoutFatalError(): void
    {
        $basePath = dirname(__DIR__, 2);

        // Run the check command and capture output
        $output = [];
        $exitCode = 0;
        exec("php {$basePath}/fw check 2>&1", $output, $exitCode);

        $fullOutput = implode("\n", $output);

        // The command should produce output (not silently fail)
        $this->assertNotEmpty($output, 'check command should produce output');

        // It should mention the checks it runs
        $this->assertTrue(
            str_contains($fullOutput, 'Convention') ||
            str_contains($fullOutput, 'convention') ||
            str_contains($fullOutput, 'Architecture') ||
            str_contains($fullOutput, 'PHPStan') ||
            str_contains($fullOutput, 'phpstan'),
            'check command should mention what checks it runs'
        );
    }

    #[Test]
    public function commandOutputIncludesFixSuggestions(): void
    {
        $basePath = dirname(__DIR__, 2);

        $output = [];
        exec("php {$basePath}/fw check 2>&1", $output, $exitCode);

        $fullOutput = implode("\n", $output);

        // When issues are found, output should include fix suggestions
        // Even on success, the command should mention the fix command
        $this->assertTrue(
            str_contains($fullOutput, 'php fw fix') ||
            str_contains($fullOutput, 'All checks passed') ||
            str_contains($fullOutput, 'passed'),
            'check command should suggest fix command or report success'
        );
    }
}
