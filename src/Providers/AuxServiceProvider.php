<?php

declare(strict_types=1);

namespace Fw\Providers;

use Fw\Aux\Http\AgentController;
use Fw\Aux\Http\McpSseController;
use Fw\Aux\Mcp\McpProtocol;
use Fw\Aux\Mcp\McpServer;
use Fw\Aux\ToolRegistry;
use Fw\Core\ServiceProvider;
use Fw\Middleware\ApiAuthMiddleware;

class AuxServiceProvider extends ServiceProvider
{
    protected array $tools = [];

    public function register(): void
    {
        $this->container->singleton(ToolRegistry::class, function () {
            $registry = new ToolRegistry();

            foreach ($this->tools as $toolClass) {
                if (is_string($toolClass) && class_exists($toolClass)) {
                    $tool = $toolClass::make();
                    $registry->register($tool);
                }
            }

            return $registry;
        });

        $this->container->singleton(McpProtocol::class, function () {
            return new McpProtocol(
                $this->container->get(ToolRegistry::class),
                serverName: $this->app->config('aux.mcp_server_name', 'VibeFW AUX'),
                serverVersion: $this->app->config('aux.mcp_server_version', '3.0'),
            );
        });

        $this->container->singleton(McpServer::class, function () {
            return new McpServer(
                $this->container->get(McpProtocol::class),
            );
        });
    }

    public function boot(): void
    {
        $router = $this->app->router;

        // MCP transport routes (unauthenticated — ability gating is inside ToolRegistry)
        $router->group('/mcp', function ($r) {
            $r->get('/sse', [McpSseController::class, 'sse']);
            $r->post('/messages', [McpSseController::class, 'messages']);
        });

        // HTTP agent routes (require API token auth)
        $router->group('/agent', function ($r) {
            $r->get('/tools', [AgentController::class, 'index']);
            $r->post('/tools/{name}', [AgentController::class, 'invoke']);
        }, middleware: [ApiAuthMiddleware::class]);
    }
}
