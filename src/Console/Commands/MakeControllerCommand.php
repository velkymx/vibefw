<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;

/**
 * Generate a new controller class.
 */
final class MakeControllerCommand extends Command
{
    protected string $name = 'make:controller';

    protected string $description = 'Create a new controller class';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('name', 'The name of the controller', true);
        $this->addOption('resource', 'Generate a resource controller with CRUD methods', false, 'r');
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        if ($name === null) {
            $this->error('Controller name is required.');
            return 1;
        }

        $basePath = $this->app->getBasePath();

        // Parse name for subdirectories (e.g., Api/PostController)
        $parts = explode('/', $name);
        $className = array_pop($parts);
        $namespace = ! empty($parts) ? '\\' . implode('\\', $parts) : '';
        $subDir = ! empty($parts) ? '/' . implode('/', $parts) : '';

        // Ensure name ends with Controller
        if (! str_ends_with($className, 'Controller')) {
            $className .= 'Controller';
        }

        // Validate name
        if (! preg_match('/^[A-Z][a-zA-Z0-9]*Controller$/', $className)) {
            $this->error('Controller name must be PascalCase ending with Controller.');
            return 1;
        }

        $controllerPath = $basePath . '/app/Controllers' . $subDir . '/' . $className . '.php';

        // Check if already exists
        if (file_exists($controllerPath)) {
            $this->error("Controller already exists: $controllerPath");
            return 1;
        }

        // Choose stub
        $stubName = $this->hasOption('resource') ? 'controller.resource.stub' : 'controller.stub';
        $stubPath = $basePath . '/stubs/' . $stubName;

        if (! file_exists($stubPath)) {
            $this->error("Stub file not found: $stubPath");
            return 1;
        }

        $stub = file_get_contents($stubPath);

        // Derive view path and route prefix from controller name
        $resourceName = str_replace('Controller', '', $className);
        $viewPath = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $resourceName));
        $routePrefix = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $resourceName));

        // Replace placeholders
        $content = str_replace(
            ['{{NAMESPACE}}', '{{CLASS_NAME}}', '{{VIEW_PATH}}', '{{ROUTE_PREFIX}}'],
            [$namespace, $className, $viewPath, $routePrefix],
            $stub
        );

        // Ensure directory exists
        $dir = dirname($controllerPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        // Write file
        file_put_contents($controllerPath, $content);

        $relativePath = 'app/Controllers' . $subDir . '/' . $className . '.php';
        $this->success("Controller created: $relativePath");

        if ($this->hasOption('resource')) {
            $this->comment('Resource methods: index, create, store, show, edit, update, destroy');
        }

        return 0;
    }
}
