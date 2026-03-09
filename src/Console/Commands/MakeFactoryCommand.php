<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;

/**
 * Generate a new model factory class.
 */
final class MakeFactoryCommand extends Command
{
    protected string $name = 'make:factory';

    protected string $description = 'Create a new model factory class';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('name', 'The name of the factory (e.g., UserFactory or User)', true);
        $this->addOption('model', 'The model class the factory is for', null, 'm');
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        if ($name === null) {
            $this->error('Factory name is required.');
            return 1;
        }

        // Normalize name - add Factory suffix if not present
        if (!str_ends_with($name, 'Factory')) {
            $name .= 'Factory';
        }

        // Ensure name is valid
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*Factory$/', $name)) {
            $this->error('Factory name must be PascalCase (e.g., UserFactory, PostFactory).');
            return 1;
        }

        $basePath = $this->app->getBasePath();
        $factoryPath = $basePath . '/database/factories/' . $name . '.php';

        // Check if already exists
        if (file_exists($factoryPath)) {
            $this->error("Factory already exists: $factoryPath");
            return 1;
        }

        // Determine model name
        $modelName = $this->option('model');
        if ($modelName === null) {
            // Derive from factory name: UserFactory -> User
            $modelName = substr($name, 0, -7); // Remove "Factory" suffix
        }

        // Read stub
        $stubPath = $basePath . '/stubs/factory.stub';
        if (!file_exists($stubPath)) {
            $this->error("Stub file not found: $stubPath");
            return 1;
        }

        $stub = file_get_contents($stubPath);

        // Replace placeholders
        $content = str_replace(
            ['{{CLASS_NAME}}', '{{MODEL_NAME}}', '{{MODEL_CLASS}}'],
            [$name, $modelName, "App\\Models\\$modelName"],
            $stub
        );

        // Ensure directory exists
        $dir = dirname($factoryPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        // Write file
        file_put_contents($factoryPath, $content);
        $this->success("Factory created: database/factories/$name.php");
        $this->info("  Model: App\\Models\\$modelName");

        return 0;
    }
}
