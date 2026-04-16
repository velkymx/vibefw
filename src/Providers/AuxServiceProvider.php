<?php

declare(strict_types=1);

namespace Fw\Providers;

use Fw\Aux\Mcp\McpProtocol;
use Fw\Aux\Mcp\McpServer;
use Fw\Aux\ToolRegistry;
use Fw\Core\ServiceProvider;

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
}
