<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use FilesystemIterator;
use Fw\Console\Application;
use Fw\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Run all validation checks.
 */
final class ValidateAllCommand extends Command
{
    protected string $name = 'validate:all';

    protected string $description = 'Run all validation checks (config, security, style, static analysis)';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addOption('fix', 'Auto-fix code style issues', false);
    }

    public function handle(): int
    {
        $basePath = $this->app->getBasePath();
        $failed = false;

        $this->info('Running all validation checks...');
        $this->newLine();

        // 1. Config validation
        $this->line($this->output->color('[1/5] Configuration validation', 'yellow'));
        $exitCode = $this->runCommand('php', ['fw', 'validate:config']);
        if ($exitCode !== 0) {
            $failed = true;
        }
        $this->newLine();

        // 2. Security scan
        $this->line($this->output->color('[2/5] Security scan', 'yellow'));
        $exitCode = $this->runCommand('php', ['fw', 'validate:security']);
        if ($exitCode !== 0) {
            $failed = true;
        }
        $this->newLine();

        // 3. PHP Syntax
        $this->line($this->output->color('[3/5] PHP syntax check', 'yellow'));
        $exitCode = $this->checkPhpSyntax($basePath);
        if ($exitCode !== 0) {
            $failed = true;
        }
        $this->newLine();

        // 4. Code style (PHP-CS-Fixer)
        $this->line($this->output->color('[4/5] Code style (PHP-CS-Fixer)', 'yellow'));
        if (file_exists($basePath . '/vendor/bin/php-cs-fixer')) {
            $args = ['vendor/bin/php-cs-fixer', 'fix'];
            if (!$this->hasOption('fix')) {
                $args[] = '--dry-run';
            }
            $args[] = '--diff';
            $exitCode = $this->runCommand('php', $args);
            if ($exitCode !== 0 && !$this->hasOption('fix')) {
                $this->warning('Code style issues found. Run with --fix to auto-fix.');
                $failed = true;
            }
        } else {
            $this->comment('PHP-CS-Fixer not installed, skipping...');
        }
        $this->newLine();

        // 5. Static analysis (PHPStan)
        $this->line($this->output->color('[5/5] Static analysis (PHPStan)', 'yellow'));
        if (file_exists($basePath . '/vendor/bin/phpstan')) {
            $exitCode = $this->runCommand('php', ['vendor/bin/phpstan', 'analyse', '--no-progress']);
            if ($exitCode !== 0) {
                $failed = true;
            }
        } else {
            $this->comment('PHPStan not installed, skipping...');
        }
        $this->newLine();

        // Summary
        $this->line(str_repeat('=', 50));
        if ($failed) {
            $this->error('Some validation checks failed');
            return 1;
        }

        $this->success('All validation checks passed!');
        return 0;
    }

    /**
     * Run a command and return exit code.
     *
     * @param array<string> $args
     */
    private function runCommand(string $command, array $args): int
    {
        $fullCommand = $command . ' ' . implode(' ', array_map('escapeshellarg', $args));
        passthru($fullCommand, $exitCode);
        return $exitCode;
    }

    /**
     * Check PHP syntax for all files.
     */
    private function checkPhpSyntax(string $basePath): int
    {
        $directories = ['src', 'app', 'config'];
        $errors = 0;

        foreach ($directories as $dir) {
            $path = $basePath . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $output = [];
                $exitCode = 0;
                exec('php -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $exitCode);

                if ($exitCode !== 0) {
                    $relativePath = str_replace($basePath . '/', '', $file->getPathname());
                    $this->error("Syntax error in: $relativePath");
                    $this->line('  ' . implode("\n  ", $output));
                    $errors++;
                }
            }
        }

        if ($errors === 0) {
            $this->success('PHP syntax OK');
        }

        return $errors > 0 ? 1 : 0;
    }
}
