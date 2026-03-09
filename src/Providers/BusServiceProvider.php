<?php

declare(strict_types=1);

namespace Fw\Providers;

use Fw\Bus\CommandBus;
use Fw\Bus\Handler;
use Fw\Bus\QueryBus;
use Fw\Core\ServiceProvider;

/**
 * Framework Bus Service Provider.
 *
 * Registers command and query handlers with the buses.
 * Application providers can extend this to register their handlers.
 *
 * Example:
 *     class AppBusServiceProvider extends BusServiceProvider
 *     {
 *         protected array $commands = [
 *             CreateUser::class => CreateUserHandler::class,
 *             UpdateProfile::class => UpdateProfileHandler::class,
 *         ];
 *
 *         protected array $queries = [
 *             GetUserById::class => GetUserByIdHandler::class,
 *         ];
 *     }
 */
class BusServiceProvider extends ServiceProvider
{
    /**
     * Command to handler mappings.
     *
     * @var array<class-string, class-string<Handler>|callable>
     */
    protected array $commands = [];

    /**
     * Query to handler mappings.
     *
     * @var array<class-string, class-string<Handler>|callable>
     */
    protected array $queries = [];

    /**
     * Command bus middleware.
     *
     * @var list<callable>
     */
    protected array $commandMiddleware = [];

    /**
     * Query bus middleware.
     *
     * @var list<callable>
     */
    protected array $queryMiddleware = [];

    public function register(): void
    {
        // Buses are already created in Application
        // This provider just registers handlers
    }

    public function boot(): void
    {
        $this->registerCommandHandlers();
        $this->registerQueryHandlers();
    }

    /**
     * Get the registered command handlers.
     *
     * @return array<class-string, class-string<Handler>|callable>
     */
    public function getCommandHandlers(): array
    {
        return $this->commands;
    }

    /**
     * Get the registered query handlers.
     *
     * @return array<class-string, class-string<Handler>|callable>
     */
    public function getQueryHandlers(): array
    {
        return $this->queries;
    }

    protected function registerCommandHandlers(): void
    {
        $bus = $this->container->get(CommandBus::class);

        // Register middleware
        foreach ($this->commandMiddleware as $middleware) {
            $bus->middleware($middleware);
        }

        // Register handlers
        foreach ($this->commands as $command => $handler) {
            $bus->register($command, $handler);
        }
    }

    protected function registerQueryHandlers(): void
    {
        $bus = $this->container->get(QueryBus::class);

        // Register middleware
        foreach ($this->queryMiddleware as $middleware) {
            $bus->middleware($middleware);
        }

        // Register handlers
        foreach ($this->queries as $query => $handler) {
            $bus->register($query, $handler);
        }
    }
}
