<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Database\Connection;
use Throwable;

/**
 * Run database seeders.
 */
final class DbSeedCommand extends Command
{
    protected string $name = 'db:seed';

    protected string $description = 'Seed the database with records';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addOption('class', 'The class name of the seeder to run', null, 'c');
        $this->addOption('force', 'Force the operation to run in production', false, 'f');
    }

    public function handle(): int
    {
        $basePath = $this->app->getBasePath();

        // Check for production safety
        if (getenv('APP_ENV') === 'production' && !$this->hasOption('force')) {
            $this->error('Cannot seed in production without --force flag.');
            return 1;
        }

        // Get database connection
        $db = $this->getConnection($basePath);
        if ($db === null) {
            $this->error('Database connection failed.');
            return 1;
        }

        $seederClass = $this->option('class');

        if ($seederClass !== null) {
            // Run specific seeder
            return $this->runSeeder($basePath, $seederClass, $db);
        }

        // Run DatabaseSeeder (main seeder)
        return $this->runSeeder($basePath, 'DatabaseSeeder', $db);
    }

    private function runSeeder(string $basePath, string $className, Connection $db): int
    {
        // Ensure class name has correct namespace
        if (!str_starts_with($className, 'Database\\Seeders\\')) {
            $className = 'Database\\Seeders\\' . $className;
        }

        // Load the seeder file manually
        $shortName = str_replace('Database\\Seeders\\', '', $className);
        $seederPath = $basePath . '/database/seeders/' . $shortName . '.php';

        if (!file_exists($seederPath)) {
            $this->error("Seeder not found: $seederPath");
            return 1;
        }

        require_once $seederPath;

        if (!class_exists($className)) {
            $this->error("Seeder class not found: $className");
            return 1;
        }

        $this->info("Seeding: $shortName");

        try {
            $seeder = new $className($db);
            $seeder->run();
            $this->success("Seeded: $shortName");
        } catch (Throwable $e) {
            $this->error("Error seeding $shortName: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function getConnection(string $basePath): ?Connection
    {
        // Load config
        $configPath = $basePath . '/config/database.php';
        if (!file_exists($configPath)) {
            return null;
        }

        $config = require $configPath;

        if (!($config['enabled'] ?? true)) {
            return null;
        }

        try {
            return new Connection($config);
        } catch (Throwable) {
            return null;
        }
    }
}
