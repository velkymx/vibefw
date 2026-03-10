<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;
use Throwable;

/**
 * List all registered routes.
 */
final class RoutesListCommand extends Command
{
    protected string $name = 'routes:list';

    protected string $description = 'List all registered routes';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addOption('method', 'Filter by HTTP method', null);
        $this->addOption('path', 'Filter by path pattern', null);
    }

    public function handle(): int
    {
        $basePath = $this->app->getBasePath();

        // Load routes
        $routesFile = $basePath . '/config/routes.php';
        if (! file_exists($routesFile)) {
            // Try alternative location
            $routesFile = $basePath . '/routes/web.php';
        }
        if (! file_exists($routesFile)) {
            $this->error('Routes file not found. Checked: config/routes.php, routes/web.php');
            return 1;
        }

        // We need to parse the routes file or load from the router
        // For now, let's try to instantiate the app and get routes
        try {
            $routes = $this->parseRoutes($routesFile);
        } catch (Throwable $e) {
            $this->error('Failed to parse routes: ' . $e->getMessage());
            return 1;
        }

        if (empty($routes)) {
            $this->comment('No routes found.');
            return 0;
        }

        // Apply filters
        $methodFilter = $this->option('method');
        $pathFilter = $this->option('path');

        if ($methodFilter !== null) {
            $methodFilter = strtoupper((string) $methodFilter);
            $routes = array_filter($routes, fn ($r) => $r['method'] === $methodFilter);
        }

        if ($pathFilter !== null) {
            $routes = array_filter($routes, fn ($r) => str_contains($r['uri'], (string) $pathFilter));
        }

        $this->newLine();
        $this->info('Registered Routes');
        $this->newLine();

        // Build table
        $rows = [];
        foreach ($routes as $route) {
            $method = $this->colorMethod($route['method']);
            $rows[] = [
                $method,
                $route['uri'],
                $route['action'],
                $route['middleware'],
            ];
        }

        $this->table(['Method', 'URI', 'Action', 'Middleware'], $rows);
        $this->newLine();
        $this->line('Total: ' . count($routes) . ' route(s)');
        $this->newLine();

        return 0;
    }

    /**
     * Parse routes from the routes file.
     *
     * @return array<array{method: string, uri: string, action: string, middleware: string}>
     */
    private function parseRoutes(string $file): array
    {
        $content = file_get_contents($file);
        $routes = [];

        // Parse $router->get/post/put/delete patterns
        $pattern = '/\$router->(get|post|put|patch|delete|any)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*([^)]+)\)/i';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $method = strtoupper($match[1]);
                $uri = $match[2];
                $action = trim($match[3]);

                // Clean up the action
                $action = $this->parseAction($action);

                $routes[] = [
                    'method' => $method,
                    'uri' => $uri,
                    'action' => $action,
                    'middleware' => '',
                ];
            }
        }

        // Also try Route::get/post pattern for backwards compatibility
        $pattern2 = '/Route::(get|post|put|patch|delete|any)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*([^)]+)\)/i';

        if (preg_match_all($pattern2, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $method = strtoupper($match[1]);
                $uri = $match[2];
                $action = trim($match[3]);
                $action = $this->parseAction($action);

                $routes[] = [
                    'method' => $method,
                    'uri' => $uri,
                    'action' => $action,
                    'middleware' => '',
                ];
            }
        }

        return $routes;
    }

    /**
     * Parse action from route definition.
     */
    private function parseAction(string $action): string
    {
        // Array notation: [Controller::class, 'method']
        if (str_contains($action, '::class')) {
            if (preg_match('/\[([^:]+)::class\s*,\s*[\'"]([^\'"]+)[\'"]\]/', $action, $m)) {
                return $m[1] . '@' . $m[2];
            }
            if (preg_match('/([^:]+)::class/', $action, $m)) {
                return $m[1] . '@__invoke';
            }
        }

        // String notation: 'Controller@method'
        if (str_contains($action, '@')) {
            return trim($action, "'\" ");
        }

        // Closure
        if (str_contains($action, 'function') || str_contains($action, 'fn')) {
            return 'Closure';
        }

        return trim($action, "'\" ");
    }

    /**
     * Add color to HTTP method.
     */
    private function colorMethod(string $method): string
    {
        return match ($method) {
            'GET' => $this->output->color('GET', 'green'),
            'POST' => $this->output->color('POST', 'yellow'),
            'PUT', 'PATCH' => $this->output->color($method, 'blue'),
            'DELETE' => $this->output->color('DELETE', 'red'),
            default => $method,
        };
    }
}
