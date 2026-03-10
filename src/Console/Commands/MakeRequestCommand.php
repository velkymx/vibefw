<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;

/**
 * Generate a new form request class.
 */
final class MakeRequestCommand extends Command
{
    protected string $name = 'make:request';

    protected string $description = 'Create a new form request class';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('name', 'The name of the request (e.g., CreatePostRequest)', true);
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        if ($name === null) {
            $this->error('Request name is required.');
            return 1;
        }

        // Normalize name - add Request suffix if not present
        if (!str_ends_with($name, 'Request')) {
            $name .= 'Request';
        }

        // Ensure name is valid
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*Request$/', $name)) {
            $this->error('Request name must be PascalCase (e.g., CreatePostRequest).');
            return 1;
        }

        $basePath = $this->app->getBasePath();
        $requestPath = $basePath . '/app/Requests/' . $name . '.php';

        // Check if already exists
        if (file_exists($requestPath)) {
            $this->error("Request already exists: $requestPath");
            return 1;
        }

        // Read stub
        $stubPath = $basePath . '/stubs/request.stub';
        if (!file_exists($stubPath)) {
            $this->error("Stub file not found: $stubPath");
            return 1;
        }

        $stub = file_get_contents($stubPath);

        // Extract base name without Request suffix for display
        $baseName = substr($name, 0, -7);

        // Replace placeholders
        $content = str_replace(
            ['{{CLASS_NAME}}', '{{NAME}}'],
            [$name, $baseName],
            $stub
        );

        // Ensure directory exists
        $dir = dirname($requestPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        // Write file
        file_put_contents($requestPath, $content);
        $this->success("Request created: app/Requests/$name.php");

        return 0;
    }
}
