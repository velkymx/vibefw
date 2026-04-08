<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class TestForCommand extends Command
{
    protected string $name = 'test:for';

    protected string $description = 'Find and list test files for a feature topic';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('topic', 'Feature topic to find tests for', true);
    }

    public function handle(): int
    {
        $topic = $this->argument('topic');
        if ($topic === null || $topic === '') {
            $this->error('Usage: php fw test:for <topic>');
            return 1;
        }

        $basePath = $this->app->getBasePath();
        $testsDir = $basePath . '/tests';

        if (!is_dir($testsDir)) {
            $this->error('No tests/ directory found.');
            return 1;
        }

        $topicLower = strtolower($topic);
        $matches = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testsDir)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filename = $file->getBasename('.php');
            if (str_contains(strtolower($filename), $topicLower)) {
                $relativePath = str_replace($basePath . '/', '', $file->getPathname());
                $matches[] = $relativePath;
            }
        }

        if (empty($matches)) {
            $this->comment("No test files found matching '{$topic}'.");
            $this->line("Generate tests with: php fw make:test {$topic}");
            return 0;
        }

        sort($matches);

        $this->newLine();
        $this->info("Tests for: {$topic}");
        $this->newLine();

        foreach ($matches as $path) {
            $this->line("  {$path}");
        }

        $this->newLine();
        $this->line('Run: php fw test --filter=' . ucfirst($topic));
        $this->newLine();

        return 0;
    }
}
