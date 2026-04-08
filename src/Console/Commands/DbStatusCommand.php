<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Core\Env;

final class DbStatusCommand extends Command
{
    protected string $name = 'db:status';

    protected string $description = 'Show database connection state and pending migrations';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function handle(): int
    {
        $basePath = $this->app->getBasePath();
        $envFile = $basePath . '/.env';

        if (file_exists($envFile)) {
            Env::load($envFile);
        }

        $driver = Env::get('DB_DRIVER', 'sqlite');
        $database = Env::get('DB_DATABASE', ':memory:');
        $host = Env::get('DB_HOST', '');

        $this->newLine();
        $this->info('Database Status');
        $this->line(str_repeat('─', 40));

        $this->line("  Driver:     {$driver}");
        if ($driver !== 'sqlite') {
            $this->line("  Host:       {$host}");
            $this->line("  Port:       " . Env::get('DB_PORT', '3306'));
        }
        $this->line("  Database:   {$database}");
        $this->newLine();

        // Count migration files
        $migrationsDir = $basePath . '/database/migrations';
        $migrationFiles = 0;
        if (is_dir($migrationsDir)) {
            $files = glob($migrationsDir . '/*.php');
            $migrationFiles = $files !== false ? count($files) : 0;
        }

        $this->line($this->output->color('  Migrations:', 'yellow'));
        $this->line("    Files:    {$migrationFiles}");
        $this->line('    Run: php fw migrate:status for details');
        $this->newLine();

        return 0;
    }
}
