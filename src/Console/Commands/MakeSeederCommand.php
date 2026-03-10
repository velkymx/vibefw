<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;

/**
 * Generate a new database seeder class.
 */
final class MakeSeederCommand extends Command
{
    protected string $name = 'make:seeder';

    protected string $description = 'Create a new database seeder class';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('name', 'The name of the seeder', true);
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        if ($name === null) {
            $this->error('Seeder name is required.');
            return 1;
        }

        // Ensure name ends with Seeder
        if (!str_ends_with($name, 'Seeder')) {
            $name .= 'Seeder';
        }

        // Ensure name is valid
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            $this->error('Seeder name must be PascalCase (e.g., UserSeeder).');
            return 1;
        }

        $basePath = $this->app->getBasePath();
        $seederPath = $basePath . '/database/seeders/' . $name . '.php';

        // Check if already exists
        if (file_exists($seederPath)) {
            $this->error("Seeder already exists: $seederPath");
            return 1;
        }

        // Read stub
        $stubPath = $basePath . '/stubs/seeder.stub';
        if (!file_exists($stubPath)) {
            $this->error("Stub file not found: $stubPath");
            return 1;
        }

        $stub = file_get_contents($stubPath);

        // Replace placeholders
        $content = str_replace('{{CLASS_NAME}}', $name, $stub);

        // Ensure directory exists
        $dir = dirname($seederPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        // Write file
        file_put_contents($seederPath, $content);
        $this->success("Seeder created: database/seeders/$name.php");

        return 0;
    }
}
