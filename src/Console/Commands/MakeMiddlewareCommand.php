<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;

/**
 * Generate a new middleware class.
 */
final class MakeMiddlewareCommand extends Command
{
    protected string $name = 'make:middleware';

    protected string $description = 'Create a new middleware class';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('name', 'The name of the middleware', true);
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        if ($name === null) {
            $this->error('Middleware name is required.');
            return 1;
        }

        // Ensure name ends with Middleware
        if (! str_ends_with($name, 'Middleware')) {
            $name .= 'Middleware';
        }

        // Validate name
        if (! preg_match('/^[A-Z][a-zA-Z0-9]*Middleware$/', $name)) {
            $this->error('Middleware name must be PascalCase ending with Middleware.');
            return 1;
        }

        $basePath = $this->app->getBasePath();
        $middlewarePath = $basePath . '/src/Middleware/' . $name . '.php';

        // Check if already exists
        if (file_exists($middlewarePath)) {
            $this->error("Middleware already exists: $middlewarePath");
            return 1;
        }

        // Read stub
        $stubPath = $basePath . '/stubs/middleware.stub';
        if (! file_exists($stubPath)) {
            $this->error("Stub file not found: $stubPath");
            return 1;
        }

        $stub = file_get_contents($stubPath);

        // Replace placeholders
        $content = str_replace('{{CLASS_NAME}}', $name, $stub);

        // Ensure directory exists
        $dir = dirname($middlewarePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        // Write file
        file_put_contents($middlewarePath, $content);
        $this->success("Middleware created: src/Middleware/$name.php");

        // Suggest adding to middleware config
        $this->newLine();
        $this->comment("Don't forget to register your middleware in config/middleware.php:");
        $alias = lcfirst(str_replace('Middleware', '', $name));
        $this->line("  '$alias' => \\Fw\\Middleware\\$name::class,");

        return 0;
    }
}
