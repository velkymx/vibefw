<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;

/**
 * Generate a new model class.
 */
final class MakeModelCommand extends Command
{
    protected string $name = 'make:model';

    protected string $description = 'Create a new model class';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('name', 'The name of the model', true);
        $this->addOption('migration', 'Create a migration file for the model', false, 'm');
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        if ($name === null) {
            $this->error('Model name is required.');
            return 1;
        }

        // Ensure name is valid
        if (! preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            $this->error('Model name must be PascalCase (e.g., Post, UserProfile).');
            return 1;
        }

        $basePath = $this->app->getBasePath();
        $modelPath = $basePath . '/app/Models/' . $name . '.php';

        // Check if already exists
        if (file_exists($modelPath)) {
            $this->error("Model already exists: $modelPath");
            return 1;
        }

        // Read stub
        $stubPath = $basePath . '/stubs/model.stub';
        if (! file_exists($stubPath)) {
            $this->error("Stub file not found: $stubPath");
            return 1;
        }

        $stub = file_get_contents($stubPath);

        // Replace placeholders
        $tableName = $this->toSnakeCase($this->pluralize($name));
        $content = str_replace(
            ['{{CLASS_NAME}}', '{{TABLE_NAME}}'],
            [$name, $tableName],
            $stub
        );

        // Ensure directory exists
        $dir = dirname($modelPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        // Write file
        file_put_contents($modelPath, $content);
        $this->success("Model created: app/Models/$name.php");

        // Create migration if requested
        if ($this->hasOption('migration')) {
            $this->createMigration($tableName);
        }

        return 0;
    }

    private function createMigration(string $tableName): void
    {
        $basePath = $this->app->getBasePath();
        $migrationsDir = $basePath . '/database/migrations';

        // Find next migration number
        $files = glob($migrationsDir . '/*.php');
        $maxNumber = 0;
        foreach ($files as $file) {
            if (preg_match('/^(\d+)_/', basename($file), $matches)) {
                $maxNumber = max($maxNumber, (int) $matches[1]);
            }
        }
        $nextNumber = str_pad((string) ($maxNumber + 1), 4, '0', STR_PAD_LEFT);

        $migrationName = "create_{$tableName}_table";
        $migrationPath = "$migrationsDir/{$nextNumber}_{$migrationName}.php";

        // Read stub
        $stubPath = $basePath . '/stubs/migration.create.stub';
        if (! file_exists($stubPath)) {
            $this->warning("Migration stub not found, skipping migration creation.");
            return;
        }

        $stub = file_get_contents($stubPath);
        $content = str_replace('{{TABLE_NAME}}', $tableName, $stub);

        file_put_contents($migrationPath, $content);
        $this->success("Migration created: database/migrations/{$nextNumber}_{$migrationName}.php");
    }

    private function toSnakeCase(string $string): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $string));
    }

    private function pluralize(string $word): string
    {
        // Simple English pluralization rules
        if (preg_match('/(s|x|z|ch|sh)$/i', $word)) {
            return $word . 'es';
        }
        if (preg_match('/[^aeiou]y$/i', $word)) {
            return substr($word, 0, -1) . 'ies';
        }
        if (preg_match('/f$/i', $word)) {
            return substr($word, 0, -1) . 'ves';
        }
        if (preg_match('/fe$/i', $word)) {
            return substr($word, 0, -2) . 'ves';
        }
        return $word . 's';
    }
}
