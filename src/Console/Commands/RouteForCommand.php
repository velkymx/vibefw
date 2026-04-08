<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;

final class RouteForCommand extends Command
{
    protected string $name = 'route:for';

    protected string $description = 'Show routes matching a feature topic';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('topic', 'Feature topic to filter routes by', true);
    }

    public function handle(): int
    {
        $topic = $this->argument('topic');
        if ($topic === null || $topic === '') {
            $this->error('Usage: php fw route:for <topic>');
            return 1;
        }

        $basePath = $this->app->getBasePath();
        $routesFile = $basePath . '/config/routes.php';

        if (!file_exists($routesFile)) {
            $this->error('Routes file not found: config/routes.php');
            return 1;
        }

        $content = (string) file_get_contents($routesFile);
        $routes = $this->parseRoutes($content);

        $topicLower = strtolower($topic);
        $filtered = array_filter($routes, function (array $route) use ($topicLower): bool {
            return str_contains(strtolower($route['uri']), $topicLower)
                || str_contains(strtolower($route['action']), $topicLower);
        });

        if (empty($filtered)) {
            $this->comment("No routes found matching '{$topic}'.");
            $this->line('Run: php fw routes:list to see all routes.');
            return 0;
        }

        $this->newLine();
        $this->info("Routes for: {$topic}");
        $this->newLine();

        $rows = [];
        foreach ($filtered as $route) {
            $rows[] = [
                $this->colorMethod($route['method']),
                $route['uri'],
                $route['action'],
            ];
        }

        $this->table(['Method', 'URI', 'Action'], $rows);
        $this->newLine();

        return 0;
    }

    /**
     * @return array<array{method: string, uri: string, action: string}>
     */
    private function parseRoutes(string $content): array
    {
        $routes = [];
        $pattern = '/\$router->(get|post|put|patch|delete|any)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*([^)]+)\)/i';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $routes[] = [
                    'method' => strtoupper($match[1]),
                    'uri' => $match[2],
                    'action' => $this->parseAction(trim($match[3])),
                ];
            }
        }

        return $routes;
    }

    private function parseAction(string $action): string
    {
        if (preg_match('/\[([^:]+)::class\s*,\s*[\'"]([^\'"]+)[\'"]\]/', $action, $m)) {
            return $m[1] . '@' . $m[2];
        }

        return trim($action, "'\" ");
    }

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
