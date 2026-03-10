<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Core\Env;
use Fw\Database\Connection;
use Fw\Database\Migration\Migrator;
use PDO;
use Throwable;

/**
 * Drop all tables and re-run all migrations.
 */
final class MigrateFreshCommand extends Command
{
    protected string $name = 'migrate:fresh';

    protected string $description = 'Drop all tables and re-run all migrations';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addOption('seed', 'Run seeders after migration', false, 's');
        $this->addOption('force', 'Force the operation to run in production', false);
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

        // Production safety check
        $env = Env::string('APP_ENV', 'production');
        if ($env === 'production' && ! $this->hasOption('force')) {
            $this->error('This command is destructive. Use --force in production.');
            return 1;
        }

        $this->warning('Dropping all tables...');
        $this->newLine();

        try {
            $db = new Connection($config);

            // Disable foreign key checks for MySQL
            if ($db->driver === 'mysql') {
                $db->getPdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
            }

            // Get all tables and drop them
            $tables = $this->getTables($db);
            foreach ($tables as $table) {
                $quotedTable = $db->quoteIdentifier($table);
                $db->getPdo()->exec("DROP TABLE IF EXISTS $quotedTable");
                $this->line("Dropped: $table");
            }

            // Re-enable foreign key checks
            if ($db->driver === 'mysql') {
                $db->getPdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
            }

            $this->newLine();
            $this->info('Running migrations...');
            $this->newLine();

            // Re-create migrator and run migrations
            $migrator = new Migrator($db, $basePath . '/database/migrations');
            $migrator->ensureMigrationsTable();
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
                $this->comment('Seeder not yet implemented.');
            }

            return 0;
        } catch (Throwable $e) {
            $this->error('Fresh migration failed: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Get all table names in the database.
     *
     * @return array<string>
     */
    private function getTables(Connection $db): array
    {
        $pdo = $db->getPdo();
        $tables = [];

        switch ($db->driver) {
            case 'mysql':
                $stmt = $pdo->query('SHOW TABLES');
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $tables[] = $row[0];
                }
                break;

            case 'pgsql':
                $stmt = $pdo->query(
                    "SELECT tablename FROM pg_tables WHERE schemaname = 'public'"
                );
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $tables[] = $row[0];
                }
                break;

            case 'sqlite':
                $stmt = $pdo->query(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
                );
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $tables[] = $row[0];
                }
                break;
        }

        return $tables;
    }
}
