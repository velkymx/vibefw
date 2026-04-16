<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;

/**
 * Generate a new AUX tool and workflow.
 */
final class MakeToolCommand extends Command
{
    protected string $name = 'make:tool';

    protected string $description = 'Create a new AUX tool and workflow';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('name', 'The name of the tool (snake_case)', true);
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        if ($name === null) {
            $this->error('Tool name is required.');
            return 1;
        }

        $basePath = $this->app->getBasePath();

        if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $name)) {
            $this->error('Tool name must be snake_case, 3-64 characters, only a-z and underscores.');
            return 1;
        }

        $className = $this->toPascalCase($name);
        $workflowClass = $className . 'Workflow';

        $toolPath = $basePath . '/app/Tools/' . $className . 'Tool.php';
        $workflowPath = $basePath . '/app/Workflows/' . $workflowClass . '.php';

        if (file_exists($toolPath)) {
            $this->error("Tool already exists: $toolPath");
            return 1;
        }

        if (file_exists($workflowPath)) {
            $this->error("Workflow already exists: $workflowPath");
            return 1;
        }

        $toolStub = $basePath . '/stubs/tool.stub';
        $workflowStub = $basePath . '/stubs/workflow.stub';

        if (!file_exists($toolStub)) {
            $this->error("Stub file not found: $toolStub");
            return 1;
        }

        if (!file_exists($workflowStub)) {
            $this->error("Stub file not found: $workflowStub");
            return 1;
        }

        $toolContent = str_replace(
            ['{{className}}', '{{name}}', '{{description}}', '{{inputSchema}}', '{{abilities}}', '{{annotations}}', '{{workflowClass}}'],
            [$className, $name, 'Process ' . $name, "['type' => 'object', 'properties' => []]", '[]', '[]', $workflowClass],
            file_get_contents($toolStub)
        );

        $workflowContent = str_replace(
            ['{{className}}', '{{name}}', '{{description}}'],
            [$workflowClass, $name, 'Process ' . $name],
            file_get_contents($workflowStub)
        );

        $toolDir = dirname($toolPath);
        if (!is_dir($toolDir)) {
            mkdir($toolDir, 0o755, true);
        }

        $workflowDir = dirname($workflowPath);
        if (!is_dir($workflowDir)) {
            mkdir($workflowDir, 0o755, true);
        }

        file_put_contents($toolPath, $toolContent);
        file_put_contents($workflowPath, $workflowContent);

        $this->success("Tool created: app/Tools/{$className}Tool.php");
        $this->success("Workflow created: app/Workflows/{$workflowClass}.php");

        return 0;
    }

    private function toPascalCase(string $input): string
    {
        return implode('', array_map('ucfirst', explode('_', $input)));
    }
}
