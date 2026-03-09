<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;

/**
 * Generate a new migration file.
 */
final class MakeMigrationCommand extends Command
{
    protected string $name = 'make:migration';

    protected string $description = 'Create a new migration file';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('name', 'The name of the migration', true);
        $this->addOption('create', 'The table to be created', null);
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        if ($name === null) {
            $this->error('Migration name is required.');
            return 1;
        }

        // Validate name format (snake_case)
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            $this->error('Migration name must be snake_case (e.g., create_posts_table).');
            return 1;
        }

        $basePath = $this->app->getBasePath();
        $migrationsDir = $basePath . '/database/migrations';

        // Ensure directory exists
        if (! is_dir($migrationsDir)) {
            mkdir($migrationsDir, 0o755, true);
        }

        // Find next migration number
        $files = glob($migrationsDir . '/*.php');
        $maxNumber = 0;
        foreach ($files as $file) {
            if (preg_match('/^(\d+)_/', basename($file), $matches)) {
                $maxNumber = max($maxNumber, (int) $matches[1]);
            }
        }
        $nextNumber = str_pad((string) ($maxNumber + 1), 4, '0', STR_PAD_LEFT);

        $migrationPath = "$migrationsDir/{$nextNumber}_{$name}.php";

        // Check if already exists
        if (file_exists($migrationPath)) {
            $this->error("Migration already exists: $migrationPath");
            return 1;
        }

        // Determine which stub to use
        $tableName = $this->option('create');
        $isCreateTable = $tableName !== null || str_starts_with($name, 'create_');

        if ($isCreateTable) {
            // Extract table name from migration name if not provided
            if ($tableName === null) {
                if (preg_match('/^create_(.+)_table$/', $name, $matches)) {
                    $tableName = $matches[1];
                } else {
                    $tableName = str_replace(['create_', '_table'], '', $name);
                }
            }
            $stubPath = $basePath . '/stubs/migration.create.stub';
        } else {
            $stubPath = $basePath . '/stubs/migration.stub';
        }

        if (! file_exists($stubPath)) {
            $this->error("Stub file not found: $stubPath");
            return 1;
        }

        $stub = file_get_contents($stubPath);

        // Replace placeholders
        $content = $stub;
        if ($isCreateTable && $tableName !== null) {
            $content = str_replace('{{TABLE_NAME}}', $tableName, $stub);
        }

        // Write file
        file_put_contents($migrationPath, $content);

        $this->success("Migration created: database/migrations/{$nextNumber}_{$name}.php");

        return 0;
    }
}
