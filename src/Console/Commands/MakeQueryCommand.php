<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;

/**
 * Generate a new CQRS query class with handler.
 */
final class MakeQueryCommand extends Command
{
    protected string $name = 'make:query';

    protected string $description = 'Create a new CQRS query class with handler';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('name', 'The name of the query', true);
        $this->addOption('no-handler', 'Do not create a handler class', false);
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        if ($name === null) {
            $this->error('Query name is required.');
            return 1;
        }

        // Ensure name ends with Query
        if (!str_ends_with($name, 'Query')) {
            $name .= 'Query';
        }

        // Ensure name is valid
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            $this->error('Query name must be PascalCase (e.g., GetUserQuery).');
            return 1;
        }

        $basePath = $this->app->getBasePath();
        $queryPath = $basePath . '/app/Queries/' . $name . '.php';

        // Check if already exists
        if (file_exists($queryPath)) {
            $this->error("Query already exists: $queryPath");
            return 1;
        }

        // Read query stub
        $stubPath = $basePath . '/stubs/query.stub';
        if (!file_exists($stubPath)) {
            $this->error("Stub file not found: $stubPath");
            return 1;
        }

        $stub = file_get_contents($stubPath);
        $content = str_replace('{{CLASS_NAME}}', $name, $stub);

        // Ensure directory exists
        $dir = dirname($queryPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        // Write query file
        file_put_contents($queryPath, $content);
        $this->success("Query created: app/Queries/$name.php");

        // Create handler unless --no-handler flag is set
        if ($this->option('no-handler') !== true) {
            $this->createHandler($basePath, $name);
        }

        return 0;
    }

    private function createHandler(string $basePath, string $queryName): void
    {
        $handlerName = str_replace('Query', 'Handler', $queryName);
        $handlerPath = $basePath . '/app/Handlers/' . $handlerName . '.php';

        if (file_exists($handlerPath)) {
            $this->warning("Handler already exists: $handlerPath");
            return;
        }

        // Read handler stub
        $stubPath = $basePath . '/stubs/query.handler.stub';
        if (!file_exists($stubPath)) {
            $this->warning("Handler stub not found, skipping handler creation.");
            return;
        }

        $stub = file_get_contents($stubPath);
        $content = str_replace(
            ['{{CLASS_NAME}}', '{{QUERY_NAME}}'],
            [$handlerName, $queryName],
            $stub
        );

        // Ensure directory exists
        $dir = dirname($handlerPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        file_put_contents($handlerPath, $content);
        $this->success("Handler created: app/Handlers/$handlerName.php");
    }
}
