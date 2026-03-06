<?php

declare(strict_types=1);

namespace Examples\User;

use Examples\User\Commands\CreateUser;
use Examples\User\Commands\DeleteUser;
use Examples\User\Commands\UpdateUser;
use Examples\User\Events\UserCreated;
use Examples\User\Events\UserDeleted;
use Examples\User\Events\UserUpdated;
use Examples\User\Handlers\CreateUserHandler;
use Examples\User\Handlers\DeleteUserHandler;
use Examples\User\Handlers\GetAllUsersHandler;
use Examples\User\Handlers\GetUserByEmailHandler;
use Examples\User\Handlers\GetUserByIdHandler;
use Examples\User\Handlers\UpdateUserHandler;
use Examples\User\Queries\GetAllUsers;
use Examples\User\Queries\GetUserByEmail;
use Examples\User\Queries\GetUserById;
use Fw\Bus\CommandBus;
use Fw\Bus\QueryBus;
use Fw\Core\Application;
use Fw\Core\Container;
use Fw\Database\Connection;
use Fw\Events\EventDispatcher;

/**
 * Service provider for the User domain.
 *
 * Demonstrates:
 * - Registering repository in container
 * - Wiring command/query handlers
 * - Setting up event listeners
 *
 * Call UserServiceProvider::register() in your bootstrap.
 */
final class UserServiceProvider
{
    /**
     * Register all user domain services.
     */
    public static function register(Application $app): void
    {
        $container = $app->getContainer();

        // Register repository as singleton
        $container->singleton(UserRepository::class, function (Container $c) use ($app) {
            return new UserRepository($app->db);
        });

        // Register command handlers
        self::registerCommands($app->commands, $container);

        // Register query handlers
        self::registerQueries($app->queries, $container);

        // Register event listeners
        self::registerEvents($app->events);
    }

    /**
     * Register command handlers.
     */
    private static function registerCommands(CommandBus $bus, Container $container): void
    {
        $bus->register(CreateUser::class, CreateUserHandler::class);
        $bus->register(UpdateUser::class, UpdateUserHandler::class);
        $bus->register(DeleteUser::class, DeleteUserHandler::class);
    }

    /**
     * Register query handlers.
     */
    private static function registerQueries(QueryBus $bus, Container $container): void
    {
        $bus->register(GetUserById::class, GetUserByIdHandler::class);
        $bus->register(GetUserByEmail::class, GetUserByEmailHandler::class);
        $bus->register(GetAllUsers::class, GetAllUsersHandler::class);
    }

    /**
     * Register event listeners.
     */
    private static function registerEvents(EventDispatcher $events): void
    {
        // Log user creation
        $events->listen(UserCreated::class, function (UserCreated $event) {
            // In a real app, you might:
            // - Send welcome email
            // - Create audit log entry
            // - Notify admin
            error_log("User created: {$event->user->email}");
        });

        // Log user updates
        $events->listen(UserUpdated::class, function (UserUpdated $event) {
            error_log("User updated: {$event->user->id}");
        });

        // Handle user deletion
        $events->listen(UserDeleted::class, function (UserDeleted $event) {
            // Clean up related data, send notifications, etc.
            error_log("User deleted: {$event->userId}");
        });
    }
}
