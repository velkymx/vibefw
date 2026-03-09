<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;

/**
 * Run the test suite.
 */
final class TestCommand extends Command
{
    protected string $name = 'test';

    protected string $description = 'Run the test suite';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addOption('coverage', 'Generate code coverage report', false, 'c');
        $this->addOption('filter', 'Filter tests by name', null, 'f');
        $this->addOption('testsuite', 'Run specific test suite (unit, feature, integration, architecture)', null, 's');
        $this->addOption('parallel', 'Run tests in parallel', false, 'p');
    }

    public function handle(): int
    {
        $basePath = $this->app->getBasePath();
        $phpunit = $basePath . '/vendor/bin/phpunit';

        if (!file_exists($phpunit)) {
            $this->error('PHPUnit not installed. Run: composer require --dev phpunit/phpunit');
            return 1;
        }

        $args = [$phpunit];

        // Coverage
        if ($this->hasOption('coverage')) {
            $args[] = '--coverage-html';
            $args[] = 'storage/coverage';
            $args[] = '--coverage-text';
        }

        // Filter
        $filter = $this->option('filter');
        if ($filter !== null) {
            $args[] = '--filter';
            $args[] = (string) $filter;
        }

        // Test suite
        $testsuite = $this->option('testsuite');
        if ($testsuite !== null) {
            $args[] = '--testsuite';
            $args[] = (string) $testsuite;
        }

        // Parallel (requires paratest)
        if ($this->hasOption('parallel')) {
            $paratest = $basePath . '/vendor/bin/paratest';
            if (file_exists($paratest)) {
                $args[0] = $paratest;
            } else {
                $this->warning('Paratest not installed. Running sequentially.');
            }
        }

        $this->info('Running tests...');
        $this->newLine();

        // Build command
        $command = implode(' ', array_map('escapeshellarg', $args));
        passthru($command, $exitCode);

        return $exitCode;
    }
}
