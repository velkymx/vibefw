<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Core\Router;

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
        $this->addOption(
            'from-route',
            'Scaffold the tool+workflow from an existing named route',
            null,
        );
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        if ($name === null) {
            $this->error('Tool name is required.');
            return 1;
        }

        $basePath = $this->app->getBasePath();

        if (preg_match('/^[A-Z]/', $name)) {
            $name = $this->toSnakeCase($name);
        }

        if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $name)) {
            $this->error('Tool name must be snake_case or PascalCase, 3-64 characters.');
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

        $fromRoute = $this->option('from-route');

        $replacements = $fromRoute === null
            ? $this->defaultReplacements($className, $name, $workflowClass)
            : $this->fromRouteReplacements($basePath, (string) $fromRoute, $className, $name, $workflowClass);

        if ($replacements === null) {
            return 1;
        }

        [$toolStub, $workflowStub, $toolVars, $workflowVars] = $replacements;

        if (!file_exists($toolStub)) {
            $this->error("Stub file not found: $toolStub");
            return 1;
        }

        if (!file_exists($workflowStub)) {
            $this->error("Stub file not found: $workflowStub");
            return 1;
        }

        $toolContent = file_get_contents($toolStub)
            |> (fn(string $raw): string => str_replace(array_keys($toolVars), array_values($toolVars), $raw));

        $workflowContent = file_get_contents($workflowStub)
            |> (fn(string $raw): string => str_replace(array_keys($workflowVars), array_values($workflowVars), $raw));

        $this->ensureDir(dirname($toolPath));
        $this->ensureDir(dirname($workflowPath));

        file_put_contents($toolPath, $toolContent);
        file_put_contents($workflowPath, $workflowContent);

        $this->success("Tool created: app/Tools/{$className}Tool.php");
        $this->success("Workflow created: app/Workflows/{$workflowClass}.php");

        return 0;
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, string>, 3: array<string, string>}
     */
    private function defaultReplacements(string $className, string $name, string $workflowClass): array
    {
        $basePath = $this->app->getBasePath();

        return [
            $basePath . '/stubs/tool.stub',
            $basePath . '/stubs/workflow.stub',
            [
                '{{className}}' => $className,
                '{{name}}' => $name,
                '{{description}}' => 'Process ' . $name,
                '{{inputSchema}}' => "['type' => 'object', 'properties' => []]",
                '{{abilities}}' => '[]',
                '{{annotations}}' => '[]',
                '{{workflowClass}}' => $workflowClass,
            ],
            [
                '{{className}}' => $workflowClass,
                '{{name}}' => $name,
                '{{description}}' => 'Process ' . $name,
            ],
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, string>, 3: array<string, string>}|null
     */
    private function fromRouteReplacements(
        string $basePath,
        string $routeName,
        string $className,
        string $name,
        string $workflowClass,
    ): ?array {
        $route = $this->resolveRoute($basePath, $routeName);
        if ($route === null) {
            return null;
        }

        [$httpMethod, $path, $controllerClass, $controllerMethod] = $route;
        $controllerShortName = $this->shortName($controllerClass);
        $description = sprintf('Agent-facing workflow for %s::%s (%s %s)', $controllerShortName, $controllerMethod, $httpMethod, $path);

        return [
            $basePath . '/stubs/tool.from-route.stub',
            $basePath . '/stubs/workflow.from-route.stub',
            [
                '{{className}}' => $className,
                '{{name}}' => $name,
                '{{description}}' => $description,
                '{{inputSchema}}' => "['type' => 'object', 'properties' => []]",
                '{{abilities}}' => '[]',
                '{{annotations}}' => '[]',
                '{{workflowClass}}' => $workflowClass,
                '{{routeName}}' => $routeName,
                '{{routeHttpMethod}}' => $httpMethod,
                '{{routePath}}' => $path,
                '{{controllerClass}}' => $controllerClass,
                '{{controllerShortName}}' => $controllerShortName,
                '{{controllerMethod}}' => $controllerMethod,
            ],
            [
                '{{className}}' => $workflowClass,
                '{{name}}' => $name,
                '{{description}}' => $description,
                '{{routeName}}' => $routeName,
                '{{routeHttpMethod}}' => $httpMethod,
                '{{routePath}}' => $path,
                '{{controllerClass}}' => $controllerClass,
                '{{controllerShortName}}' => $controllerShortName,
                '{{controllerMethod}}' => $controllerMethod,
            ],
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}|null
     */
    private function resolveRoute(string $basePath, string $routeName): ?array
    {
        $routesFile = $basePath . '/config/routes.php';
        if (!file_exists($routesFile)) {
            $this->error("Routes file not found: $routesFile");
            return null;
        }

        $router = new Router();
        $loader = require $routesFile;
        if (!is_callable($loader)) {
            $this->error('Routes file must return a callable.');
            return null;
        }

        try {
            $loader($router);
        } catch (\Throwable $e) {
            $this->error('Failed to load routes: ' . $e->getMessage());
            return null;
        }

        $named = $router->getNamedRoutes();
        if (!isset($named[$routeName])) {
            $this->error("Named route not found: $routeName");
            return null;
        }

        $path = $named[$routeName];

        foreach ($router->getRoutes() as $httpMethod => $routes) {
            foreach ($routes as $route) {
                if ($route['path'] !== $path) {
                    continue;
                }

                $handler = $route['handler'];
                if (!is_array($handler) || count($handler) !== 2) {
                    $this->error("Route '$routeName' handler is not [Controller::class, 'method']. Cannot scaffold.");
                    return null;
                }

                [$class, $action] = $handler;
                $class = is_object($class) ? $class::class : (string) $class;

                if (!class_exists($class) || !method_exists($class, (string) $action)) {
                    $this->error("Controller {$class}::{$action} does not exist.");
                    return null;
                }

                return [$httpMethod, $path, $class, (string) $action];
            }
        }

        $this->error("Route '$routeName' has no matching method entry.");
        return null;
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts) ?: $fqcn;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
    }

    private function toPascalCase(string $input): string
    {
        return implode('', array_map('ucfirst', explode('_', $input)));
    }

    private function toSnakeCase(string $input): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
    }
}
