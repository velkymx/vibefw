<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;

/**
 * Generate a new CQRS command class with handler.
 */
final class MakeCommandCommand extends Command
{
    protected string $name = 'make:command';

    protected string $description = 'Create a new CQRS command class with handler';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('name', 'The name of the command', true);
        $this->addOption('no-handler', 'Do not create a handler class', false);
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        if ($name === null) {
            $this->error('Command name is required.');
            return 1;
        }

        // Ensure name ends with Command
        if (!str_ends_with($name, 'Command')) {
            $name .= 'Command';
        }

        // Ensure name is valid
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            $this->error('Command name must be PascalCase (e.g., CreateUserCommand).');
            return 1;
        }

        $basePath = $this->app->getBasePath();
        $commandPath = $basePath . '/app/Commands/' . $name . '.php';

        // Check if already exists
        if (file_exists($commandPath)) {
            $this->error("Command already exists: $commandPath");
            return 1;
        }

        // Read command stub
        $stubPath = $basePath . '/stubs/command.stub';
        if (!file_exists($stubPath)) {
            $this->error("Stub file not found: $stubPath");
            return 1;
        }

        $stub = file_get_contents($stubPath);
        $content = str_replace('{{CLASS_NAME}}', $name, $stub);

        // Ensure directory exists
        $dir = dirname($commandPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        // Write command file
        file_put_contents($commandPath, $content);
        $this->success("Command created: app/Commands/$name.php");

        // Create handler unless --no-handler flag is set
        if ($this->option('no-handler') !== true) {
            $this->createHandler($basePath, $name);
        }

        return 0;
    }

    private function createHandler(string $basePath, string $commandName): void
    {
        $handlerName = str_replace('Command', 'Handler', $commandName);
        $handlerPath = $basePath . '/app/Handlers/' . $handlerName . '.php';

        if (file_exists($handlerPath)) {
            $this->warning("Handler already exists: $handlerPath");
            return;
        }

        // Read handler stub
        $stubPath = $basePath . '/stubs/command.handler.stub';
        if (!file_exists($stubPath)) {
            $this->warning("Handler stub not found, skipping handler creation.");
            return;
        }

        $stub = file_get_contents($stubPath);
        $content = str_replace(
            ['{{CLASS_NAME}}', '{{COMMAND_NAME}}'],
            [$handlerName, $commandName],
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
