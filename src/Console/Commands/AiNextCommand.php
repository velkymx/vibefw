<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use FilesystemIterator;
use Fw\Console\Application;
use Fw\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Suggest the next logical step based on project state.
 */
final class AiNextCommand extends Command
{
    protected string $name = 'ai:next';

    protected string $description = 'Suggest the next step based on current project state';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function handle(): int
    {
        $basePath = $this->app->getBasePath();
        $suggestions = $this->analyze($basePath);

        if (empty($suggestions)) {
            $this->success('Project looks good! Nothing obvious to do next.');
            $this->line('  php fw check  — run a full validation');
            return 0;
        }

        $this->info('Suggested next steps:');
        $this->newLine();

        foreach ($suggestions as $i => $suggestion) {
            $num = $i + 1;
            $this->line("  {$num}. {$suggestion['description']}");
            $this->line('     ' . $this->output->color($suggestion['command'], 'green'));
            $this->newLine();
        }

        return 0;
    }

    /**
     * @return array<array{description: string, command: string}>
     */
    private function analyze(string $basePath): array
    {
        $suggestions = [];

        $hasModels = is_dir($basePath . '/app/Models') && $this->countPhpFiles($basePath . '/app/Models') > 0;
        $hasControllers = is_dir($basePath . '/app/Controllers') && $this->countPhpFiles($basePath . '/app/Controllers') > 0;
        $hasMigrations = is_dir($basePath . '/database/migrations') && $this->countPhpFiles($basePath . '/database/migrations') > 0;
        $hasRoutes = file_exists($basePath . '/config/routes.php');
        $hasTests = is_dir($basePath . '/tests') && $this->countPhpFiles($basePath . '/tests') > 0;
        $hasMap = file_exists($basePath . '/ai-app-map.md');

        // No models at all — suggest creating a resource
        if (!$hasModels) {
            $suggestions[] = [
                'description' => 'Create your first resource (model + migration + controller + views)',
                'command' => 'php fw make:schema Post',
            ];
            return $suggestions;
        }

        // Models exist but no migrations
        if ($hasModels && !$hasMigrations) {
            $modelName = $this->getFirstModelName($basePath);
            $suggestions[] = [
                'description' => "No migrations found. Create a migration for {$modelName}",
                'command' => "php fw make:migration create_" . strtolower($modelName) . "s_table",
            ];
        }

        // Models exist but no controllers
        if ($hasModels && !$hasControllers) {
            $modelName = $this->getFirstModelName($basePath);
            $suggestions[] = [
                'description' => "No controllers found. Create a controller for {$modelName}",
                'command' => "php fw make:controller {$modelName}Controller -r",
            ];
        }

        // Has models but no tests
        if ($hasModels && !$hasTests) {
            $modelName = $this->getFirstModelName($basePath);
            $suggestions[] = [
                'description' => "No tests found. Generate tests for {$modelName}",
                'command' => "php fw make:test {$modelName}",
            ];
        }

        // Models without relationships — suggest linking
        if ($hasModels && $this->countPhpFiles($basePath . '/app/Models') >= 2) {
            $models = $this->getModelNames($basePath);
            $hasRelationships = false;
            foreach ($this->phpFiles($basePath . '/app/Models') as $file) {
                $content = (string) file_get_contents($file);
                if (preg_match('/(belongsTo|hasMany|hasOne|belongsToMany)/', $content)) {
                    $hasRelationships = true;
                    break;
                }
            }
            if (!$hasRelationships && count($models) >= 2) {
                $suggestions[] = [
                    'description' => "Link your models with relationships",
                    'command' => "php fw make:link {$models[0]} {$models[1]} --hasMany",
                ];
            }
        }

        // No app map
        if (!$hasMap && $hasModels) {
            $suggestions[] = [
                'description' => 'Generate the AI project map',
                'command' => 'php fw ai:map',
            ];
        }

        // Suggest running checks
        if ($hasModels && $hasControllers) {
            $suggestions[] = [
                'description' => 'Run convention checks to validate your code',
                'command' => 'php fw check',
            ];
        }

        return $suggestions;
    }

    private function getFirstModelName(string $basePath): string
    {
        $names = $this->getModelNames($basePath);
        return $names[0] ?? 'Resource';
    }

    /**
     * @return array<string>
     */
    private function getModelNames(string $basePath): array
    {
        $path = $basePath . '/app/Models';
        if (!is_dir($path)) {
            return [];
        }

        $names = [];
        foreach ($this->phpFiles($path) as $file) {
            $names[] = pathinfo($file, PATHINFO_FILENAME);
        }
        sort($names);
        return $names;
    }

    private function countPhpFiles(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $count = 0;
        foreach ($this->phpFiles($directory) as $_) {
            $count++;
        }
        return $count;
    }

    /**
     * @return \Generator<string>
     */
    private function phpFiles(string $directory): \Generator
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
