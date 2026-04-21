<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Aux\Http\AgentAccessibleScanner;
use Fw\Aux\ToolRegistry;
use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Core\Application as HttpApplication;
use Fw\Core\Router;

final class AuxListCommand extends Command
{
    protected string $name = 'aux:list';

    protected string $description = 'List all registered AUX tools';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function handle(): int
    {
        $container = HttpApplication::getInstance()->getContainer();
        $registry = $container->get(ToolRegistry::class);
        $tools = $registry->all();

        if (empty($tools)) {
            $this->comment('No AUX tools registered.');
            return 0;
        }

        $linkedRoutes = $container->has(Router::class)
            ? new AgentAccessibleScanner($container->get(Router::class))->scan()
            : [];

        $this->newLine();
        $this->info('Registered AUX Tools');
        $this->newLine();

        $rows = [];
        foreach ($tools as $tool) {
            $abilities = $tool->abilities === [] ? 'public' : implode(', ', $tool->abilities);
            $routes = $linkedRoutes[$tool->name] ?? [];
            $routeLabel = $routes === []
                ? '—'
                : implode(', ', array_map(static fn (array $r): string => $r['name'], $routes));

            $rows[] = [
                $tool->name,
                $tool->description,
                $abilities,
                $routeLabel,
            ];
        }

        $this->table(['Name', 'Description', 'Abilities', 'Linked Routes'], $rows);
        $this->newLine();
        $this->line('Total: ' . count($tools) . ' tool(s)');
        $this->newLine();

        return 0;
    }
}
