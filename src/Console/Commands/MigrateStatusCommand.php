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
 * Show migration status.
 */
final class MigrateStatusCommand extends Command
{
    protected string $name = 'migrate:status';

    protected string $description = 'Show the status of each migration';

    public function __construct(Application $app)
    {
        parent::__construct($app);
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

        try {
            $db = new Connection($config);
            $migrator = new Migrator($db, $basePath . '/database/migrations');

            $ran = $migrator->ran();
            $pending = $migrator->pending();

            $this->newLine();
            $this->info('Migration Status');
            $this->newLine();

            // Build table data
            $rows = [];

            foreach ($ran as $migration) {
                $rows[] = [
                    $this->output->color('Yes', 'green'),
                    $migration,
                ];
            }

            foreach ($pending as $migration) {
                $rows[] = [
                    $this->output->color('No', 'yellow'),
                    $migration,
                ];
            }

            if (empty($rows)) {
                $this->comment('No migrations found.');
                return 0;
            }

            $this->table(['Ran?', 'Migration'], $rows);

            $this->newLine();
            $this->line('Ran: ' . count($ran) . ' | Pending: ' . count($pending));
            $this->newLine();

            return 0;
        } catch (Throwable $e) {
            $this->error('Failed to get status: ' . $e->getMessage());
            return 1;
        }
    }
}
