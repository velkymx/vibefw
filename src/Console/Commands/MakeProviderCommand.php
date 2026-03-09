<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;

/**
 * Generate a new service provider class.
 */
final class MakeProviderCommand extends Command
{
    protected string $name = 'make:provider';

    protected string $description = 'Create a new service provider class';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('name', 'The name of the provider', true);
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        if ($name === null) {
            $this->error('Provider name is required.');
            return 1;
        }

        // Ensure name ends with ServiceProvider
        if (!str_ends_with($name, 'ServiceProvider') && !str_ends_with($name, 'Provider')) {
            $name .= 'ServiceProvider';
        }

        // Ensure name is valid
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            $this->error('Provider name must be PascalCase (e.g., CacheServiceProvider).');
            return 1;
        }

        $basePath = $this->app->getBasePath();
        $providerPath = $basePath . '/app/Providers/' . $name . '.php';

        // Check if already exists
        if (file_exists($providerPath)) {
            $this->error("Provider already exists: $providerPath");
            return 1;
        }

        // Read stub
        $stubPath = $basePath . '/stubs/provider.stub';
        if (!file_exists($stubPath)) {
            $this->error("Stub file not found: $stubPath");
            return 1;
        }

        $stub = file_get_contents($stubPath);

        // Replace placeholders
        $content = str_replace('{{CLASS_NAME}}', $name, $stub);

        // Ensure directory exists
        $dir = dirname($providerPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        // Write file
        file_put_contents($providerPath, $content);
        $this->success("Provider created: app/Providers/$name.php");
        $this->newLine();
        $this->comment("Don't forget to register the provider in config/providers.php:");
        $this->line("  App\\Providers\\$name::class,");

        return 0;
    }
}
