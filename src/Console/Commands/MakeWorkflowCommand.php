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
        $this->addOption(
            'template',
            'Scaffold from a named stubs/workflows/<template>.stub instead of the default workflow.stub',
        );
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

        $baseName = str_ends_with($name, 'Workflow')
            ? substr($name, 0, -strlen('Workflow'))
            : $name;
        $fullName = $baseName . 'Workflow';

        $path = $basePath . '/app/Workflows/' . $fullName . '.php';

        if (file_exists($path)) {
            $this->error("Workflow already exists: $path");
            return 1;
        }

        $template = $this->option('template');
        $stubPath = $this->resolveStubPath($basePath, is_string($template) ? $template : null);

        if ($stubPath === null) {
            $this->error("Unknown workflow template '{$template}'. Expected a file at stubs/workflows/{$template}.stub.");
            return 1;
        }

        if (!file_exists($stubPath)) {
            $this->error("Stub file not found: $stubPath");
            return 1;
        }

        $description = 'Process ' . $baseName;
        $content = str_replace(
            ['{{className}}', '{{name}}', '{{description}}'],
            [$baseName, $this->toKebabCase($baseName), $description],
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

    private function resolveStubPath(string $basePath, ?string $template): ?string
    {
        if ($template === null || $template === '') {
            return $basePath . '/stubs/workflow.stub';
        }

        if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $template)) {
            return null;
        }

        return $basePath . '/stubs/workflows/' . $template . '.stub';
    }
}
