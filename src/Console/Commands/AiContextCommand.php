<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use FilesystemIterator;
use Fw\Console\Application;
use Fw\Console\Command;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Dump all files related to a feature for AI context.
 */
final class AiContextCommand extends Command
{
    protected string $name = 'ai:context';

    protected string $description = 'Dump all files for a feature (model, controller, requests, views, migrations)';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('topic', 'Feature/model name to dump context for', true);
        $this->addOption('compact', 'Strip comments from output', false);
        $this->addOption('json', 'Output as JSON', false);
    }

    public function handle(): int
    {
        $topic = $this->argument('topic');
        if ($topic === null || $topic === '') {
            $this->error('Usage: php fw ai:context <topic>');
            $this->line('Example: php fw ai:context posts');
            return 1;
        }

        $basePath = $this->app->getBasePath();
        $compact = $this->option('compact') === true;
        $json = $this->option('json') === true;

        // Normalize: "posts" → "Post", "post" → "Post"
        $modelName = ucfirst(rtrim(strtolower($topic), 's'));
        $topicLower = strtolower($topic);

        $files = $this->findRelatedFiles($basePath, $modelName, $topicLower);

        if (empty($files)) {
            $this->error("No files found for topic '{$topic}'");
            $this->line("Try: php fw ai:context <ModelName>");
            return 1;
        }

        if ($json) {
            $this->outputJson($files, $compact);
        } else {
            $this->outputText($files, $compact, $basePath);
        }

        return 0;
    }

    /**
     * @return array<string, string> filepath => content
     */
    private function findRelatedFiles(string $basePath, string $modelName, string $topicLower): array
    {
        $files = [];

        // Model
        $this->addFileIfExists($files, $basePath . "/app/Models/{$modelName}.php");

        // Controllers - look for {Model}Controller in any subdirectory
        $controllerDir = $basePath . '/app/Controllers';
        if (is_dir($controllerDir)) {
            foreach ($this->phpFiles($controllerDir) as $file) {
                if (stripos(basename($file), $modelName) !== false) {
                    $files[$file] = (string) file_get_contents($file);
                }
            }
        }

        // FormRequests
        $requestDir = $basePath . '/app/Requests';
        if (is_dir($requestDir)) {
            foreach ($this->phpFiles($requestDir) as $file) {
                if (stripos(basename($file), $modelName) !== false) {
                    $files[$file] = (string) file_get_contents($file);
                }
            }
        }

        // Views
        $viewDir = $basePath . '/app/Views/' . $topicLower;
        if (!is_dir($viewDir)) {
            $viewDir = $basePath . '/app/Views/' . $topicLower . 's';
        }
        if (is_dir($viewDir)) {
            foreach ($this->phpFiles($viewDir) as $file) {
                $files[$file] = (string) file_get_contents($file);
            }
        }

        // Migrations containing the topic name
        $migrationDir = $basePath . '/database/migrations';
        if (is_dir($migrationDir)) {
            foreach ($this->phpFiles($migrationDir) as $file) {
                $basename = strtolower(basename($file));
                if (str_contains($basename, $topicLower) || str_contains($basename, $topicLower . 's')) {
                    $files[$file] = (string) file_get_contents($file);
                }
            }
        }

        // Schema JSON
        $schemaFile = $basePath . "/app/Schemas/{$modelName}.json";
        if (file_exists($schemaFile)) {
            $files[$schemaFile] = (string) file_get_contents($schemaFile);
        }

        return $files;
    }

    private function addFileIfExists(array &$files, string $path): void
    {
        if (file_exists($path)) {
            $files[$path] = (string) file_get_contents($path);
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function outputText(array $files, bool $compact, string $basePath): void
    {
        $count = count($files);
        $this->info("Found {$count} file(s)");
        $this->newLine();

        foreach ($files as $path => $content) {
            $relative = str_replace($basePath . '/', '', $path);
            $this->line($this->output->color("=== {$relative} ===", 'yellow'));

            if ($compact) {
                $content = $this->stripComments($content);
            }

            $this->line($content);
            $this->newLine();
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function outputJson(array $files, bool $compact): void
    {
        $basePath = $this->app->getBasePath();
        $output = ['files' => []];

        foreach ($files as $path => $content) {
            $relative = str_replace($basePath . '/', '', $path);
            if ($compact) {
                $content = $this->stripComments($content);
            }
            $output['files'][] = [
                'path' => $relative,
                'content' => $content,
            ];
        }

        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function stripComments(string $content): string
    {
        // Strip block comments (/** ... */ and /* ... */)
        $content = preg_replace('/\/\*\*?.*?\*\//s', '', $content) ?? $content;

        // Strip single-line comments (// ...)
        $content = preg_replace('/^\s*\/\/.*$/m', '', $content) ?? $content;

        // Strip consecutive blank lines
        $content = preg_replace('/\n{3,}/', "\n\n", $content) ?? $content;

        return trim($content) . "\n";
    }

    /**
     * @return Generator<string>
     */
    private function phpFiles(string $directory): Generator
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $ext = $file->getExtension();
            if ($ext === 'php' || $ext === 'json') {
                yield $file->getPathname();
            }
        }
    }
}
