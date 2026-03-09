<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Core\Env;
use Fw\Database\Connection;
use Fw\Database\Migration\Migrator;
use Throwable;

/**
 * Run pending database migrations.
 */
final class MigrateCommand extends Command
{
    protected string $name = 'migrate';

    protected string $description = 'Run pending database migrations';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addOption('seed', 'Run seeders after migration', false, 's');
    }

    public function handle(): int
    {
        $basePath = $this->app->getBasePath();

        // Load environment
        Env::load($basePath . '/.env');

        // Load database config
        $config = require $basePath . '/config/database.php';

        if (! ($config['enabled'] ?? true)) {
            $this->error('Database is not enabled.');
            return 1;
        }

        $this->info('Running migrations...');
        $this->newLine();

        try {
            $db = new Connection($config);
            $migrator = new Migrator($db, $basePath . '/database/migrations');

            $pending = $migrator->pending();

            if (empty($pending)) {
                $this->comment('Nothing to migrate.');
                return 0;
            }

            $this->line('Pending migrations:');
            foreach ($pending as $migration) {
                $this->line('  - ' . $migration);
            }
            $this->newLine();

            $migrated = $migrator->migrate();

            foreach ($migrated as $migration) {
                $this->success("Migrated: $migration");
            }

            $this->newLine();
            $this->info(count($migrated) . ' migration(s) completed.');

            // Run seeders if requested
            if ($this->hasOption('seed')) {
                $this->newLine();
                $this->info('Running seeders...');
                // TODO: Implement seeder runner
                $this->comment('Seeder not yet implemented.');
            }

            return 0;
        } catch (Throwable $e) {
            $this->error('Migration failed: ' . $e->getMessage());
            return 1;
        }
    }
}
