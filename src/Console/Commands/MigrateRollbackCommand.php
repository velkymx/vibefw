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
 * Rollback the last database migration.
 */
final class MigrateRollbackCommand extends Command
{
    protected string $name = 'migrate:rollback';

    protected string $description = 'Rollback the last database migration batch';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addOption('step', 'Number of migrations to rollback', 1);
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

        $steps = (int) ($this->option('step') ?: 1);

        $this->info("Rolling back $steps migration(s)...");
        $this->newLine();

        try {
            $db = new Connection($config);
            $migrator = new Migrator($db, $basePath . '/database/migrations');

            $rolled = $migrator->rollback($steps);

            if (empty($rolled)) {
                $this->comment('Nothing to rollback.');
                return 0;
            }

            foreach ($rolled as $migration) {
                $this->success("Rolled back: $migration");
            }

            $this->newLine();
            $this->info(count($rolled) . ' migration(s) rolled back.');

            return 0;
        } catch (Throwable $e) {
            $this->error('Rollback failed: ' . $e->getMessage());
            return 1;
        }
    }
}
