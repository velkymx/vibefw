<?php

declare(strict_types=1);

namespace Fw\Providers;

use Fw\Core\ServiceProvider;
use Fw\Database\Connection;
use Fw\Queue\DatabaseDriver;
use Fw\Queue\FileDriver;
use Fw\Queue\Queue;
use Fw\Queue\SyncDriver;
use Fw\Queue\Worker;
use InvalidArgumentException;

final class QueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Queue::class, function ($container) {
            $config = $this->app->config('queue', []);
            $driverType = $config['driver'] ?? 'file';

            $driver = match ($driverType) {
                'sync' => new SyncDriver(),
                'database' => new DatabaseDriver(
                    $container->get(Connection::class),
                    $config['table'] ?? 'jobs'
                ),
                'file' => new FileDriver($config['path'] ?? BASE_PATH . '/storage/queue'),
                default => throw new InvalidArgumentException("Unknown queue driver: $driverType"),
            };

            return new Queue($driver);
        }, true); // Global singleton

        $this->container->bind(Worker::class, function ($container) {
            return new Worker($container->get(Queue::class), $container);
        });
    }
}
