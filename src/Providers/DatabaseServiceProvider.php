<?php

declare(strict_types=1);

namespace Fw\Providers;

use Fw\Core\ServiceProvider;
use Fw\Database\Connection;
use Fw\Model\Model;

/**
 * Framework Database Service Provider.
 *
 * Configures the database connection and sets up the Model class
 * to use the active connection.
 *
 * This provider bridges the legacy Database\Model and new Model\Model
 * systems, ensuring both can use the same connection.
 */
class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Connection is already registered in Application if enabled
        // This provider ensures Model classes are properly configured
    }

    public function boot(): void
    {
        // Get connection if available
        $connection = $this->container->tryGet(Connection::class);

        $connection->match(
            some: fn (Connection $conn) => Model::setConnection($conn),
            none: fn () => null,
        );
    }
}
