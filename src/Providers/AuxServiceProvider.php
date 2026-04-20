<?php

declare(strict_types=1);

namespace Fw\Providers;

use Fw\Aux\BuiltinTools;
use Fw\Aux\FeatureIndex;
use Fw\Aux\Http\AgentController;
use Fw\Aux\Http\McpSseController;
use Fw\Aux\Http\RouteAdvertiser;
use Fw\Aux\Mcp\McpProtocol;
use Fw\Aux\Mcp\McpServer;
use Fw\Aux\Notifications\DeliverAgentNotificationJob;
use Fw\Aux\Notifications\AgentNotification;
use Fw\Aux\Notifications\NotificationChannelFactory;
use Fw\Aux\Notifications\NotificationChannelRegistry;
use Fw\Aux\AuxStats;
use Fw\Aux\ToolRegistry;
use Fw\Core\ServiceProvider;
use Fw\Aux\Http\AgentMiddleware;
use Fw\Events\EventDispatcher;
use Fw\Log\Logger;
use Fw\Middleware\ApiAuthMiddleware;
use Fw\Middleware\RateLimitMiddleware;

class AuxServiceProvider extends ServiceProvider
{
    protected array $tools = [];

    public function register(): void
    {
        $this->container->singleton(FeatureIndex::class, fn(): FeatureIndex => new FeatureIndex());

        $this->container->singleton(AuxStats::class, function (): AuxStats {
            $path = defined('BASE_PATH')
                ? BASE_PATH . '/storage/cache/aux-stats.json'
                : sys_get_temp_dir() . '/aux-stats.json';
            return new AuxStats($path);
        });

        $this->container->singleton(ToolRegistry::class, function () {
            $registry = new ToolRegistry();
            $registry->setStats($this->container->get(AuxStats::class));

            $multiplier = $this->app->config('aux.budget_multiplier');
            if (is_numeric($multiplier) && (float) $multiplier > 0.0) {
                $registry->setBudgetMultiplier((float) $multiplier);
            }

            $registry->register(BuiltinTools::listFeatures(
                $this->container->get(FeatureIndex::class),
            ));

            if ($this->app->config('aux.list_routes_enabled', true)) {
                $registry->register(BuiltinTools::listRoutes(
                    new RouteAdvertiser($this->app->router),
                ));
            }

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

        $this->container->singleton(NotificationChannelRegistry::class, function () {
            $factory = new NotificationChannelFactory(
                loggerResolver: fn() => $this->container->has(Logger::class)
                    ? $this->container->get(Logger::class)
                    : Logger::getInstance(),
                events: $this->container->has(EventDispatcher::class)
                    ? $this->container->get(EventDispatcher::class)
                    : null,
            );

            $config = $this->app->config('aux.notification_channels', []);
            return $factory->build(is_array($config) ? $config : []);
        });
    }

    public function boot(): void
    {
        $registry = $this->container->get(NotificationChannelRegistry::class);
        DeliverAgentNotificationJob::setDeliverer(
            static function (AgentNotification $n, string $channel) use ($registry): void {
                if ($registry->has($channel)) {
                    $registry->deliver($channel, $n);
                }
            },
        );

        $router = $this->app->router;

        if ($this->app->config('aux.mcp_enabled', true)) {
            $router->group('/mcp', function ($r) {
                $r->get('/sse', [McpSseController::class, 'sse']);
                $r->post('/messages', [McpSseController::class, 'messages']);
            }, middleware: [AgentMiddleware::class]);
        }

        if ($this->app->config('aux.http_agent_enabled', true)) {
            $router->group('/agent', function ($r) {
                $r->get('/tools', [AgentController::class, 'index']);
                $r->post('/tools/{name}', [AgentController::class, 'invoke']);
            }, middleware: [ApiAuthMiddleware::class, AgentMiddleware::class, RateLimitMiddleware::class]);
        }
    }
}
