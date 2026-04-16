<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;

/**
 * Generate a new workflow action.
 */
final class MakeWorkflowCommand extends Command
{
    protected string $name = 'make:workflow';

    protected string $description = 'Create a new workflow action';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('name', 'The name of the workflow (PascalCase)', true);
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        if ($name === null) {
            $this->error('Workflow name is required.');
            return 1;
        }

        $basePath = $this->app->getBasePath();

        if (!preg_match('/^[A-Z][a-zA-Z0-9]{2,63}$/', $name)) {
            $this->error('Workflow name must be PascalCase, 3-64 characters.');
            return 1;
        }

        if (!str_ends_with($name, 'Workflow')) {
            $name .= 'Workflow';
        }

        $path = $basePath . '/app/Workflows/' . $name . '.php';

        if (file_exists($path)) {
            $this->error("Workflow already exists: $path");
            return 1;
        }

        $stubPath = $basePath . '/stubs/workflow.stub';

        if (!file_exists($stubPath)) {
            $this->error("Stub file not found: $stubPath");
            return 1;
        }

        $description = 'Process ' . str_replace('Workflow', '', $name);
        $content = str_replace(
            ['{{className}}', '{{name}}', '{{description}}'],
            [$name, $this->toKebabCase($name), $description],
            file_get_contents($stubPath)
        );

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        file_put_contents($path, $content);

        $this->success("Workflow created: app/Workflows/{$name}.php");

        return 0;
    }

    private function toKebabCase(string $input): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $input));
    }
}
