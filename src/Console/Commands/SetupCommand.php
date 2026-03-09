<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Command;

/**
 * SetupCommand — Initialize a new VibeFW project
 *
 * This is the CLI equivalent of scripts/setup.php. It handles first-time
 * project setup: creating .env, generating keys, creating storage dirs,
 * and running migrations.
 *
 * Usage:
 *   php fw setup              # Full setup
 *   php fw setup --force      # Overwrite existing .env
 *   php fw setup --no-migrate # Skip migrations
 */
final class SetupCommand extends Command
{
    protected string $name = 'setup';

    protected string $description = 'Initialize a new VibeFW project (create .env, generate keys, run migrations)';

    public function configure(): void
    {
        $this->options = [
            'force' => [
                'description' => 'Overwrite existing .env file',
                'default' => false,
            ],
            'no-migrate' => [
                'description' => 'Skip running migrations',
                'default' => false,
            ],
        ];
    }

    public function handle(): int
    {
        $basePath = $this->app->getBasePath();

        $this->newLine();
        $this->info('VibeFW Project Setup');
        $this->newLine();

        // 1. Environment file
        $this->setupEnv($basePath);

        // 2. Storage directories
        $this->setupStorage($basePath);

        // 3. SQLite database
        $this->setupDatabase($basePath);

        // 4. Migrations
        if (!$this->hasOption('no-migrate')) {
            $this->runMigrations();
        } else {
            $this->comment('  Skipping migrations (--no-migrate)');
        }

        // 5. Done
        $this->newLine();
        $this->success('Project ready!');
        $this->newLine();
        $this->line('  Next steps:');
        $this->newLine();
        $this->line('    php fw serve              Start the dev server');
        $this->line('    php fw make:spa           Add a Vue 3 + VibeUI frontend');
        $this->line('    php fw make:model Post -m Create a model with migration');
        $this->newLine();

        return 0;
    }

    private function setupEnv(string $basePath): void
    {
        $envFile = $basePath . '/.env';
        $envExample = $basePath . '/.env.example';
        $force = $this->hasOption('force');

        if (file_exists($envFile) && !$force) {
            $this->comment('  .env already exists (use --force to overwrite)');
            return;
        }

        if (!file_exists($envExample)) {
            $this->warning('  .env.example not found — skipping .env creation');
            return;
        }

        $this->task('Creating .env with secure keys', function () use ($envFile, $envExample) {
            $env = file_get_contents($envExample);

            $appKey = bin2hex(random_bytes(32));
            $queueKey = bin2hex(random_bytes(32));

            $env = preg_replace('/^APP_KEY=$/m', "APP_KEY={$appKey}", $env);
            $env = preg_replace('/^QUEUE_SECRET_KEY=$/m', "QUEUE_SECRET_KEY={$queueKey}", $env);

            file_put_contents($envFile, $env);
        });
    }

    private function setupStorage(string $basePath): void
    {
        $dirs = [
            'storage/cache',
            'storage/cache/ratelimit',
            'storage/cache/views',
            'storage/logs',
            'storage/queue',
        ];

        $this->task('Creating storage directories', function () use ($basePath, $dirs) {
            $created = 0;
            foreach ($dirs as $dir) {
                $fullPath = $basePath . '/' . $dir;
                if (!is_dir($fullPath)) {
                    mkdir($fullPath, 0750, true);
                    $created++;
                }
                $gitkeep = $fullPath . '/.gitkeep';
                if (!file_exists($gitkeep)) {
                    touch($gitkeep);
                }
            }
            return $created > 0 ? "created {$created} directories" : 'already exist';
        });
    }

    private function setupDatabase(string $basePath): void
    {
        $dbFile = $basePath . '/storage/database.sqlite';

        // Check if SQLite is the configured driver
        $envFile = $basePath . '/.env';
        $dbDriver = 'sqlite';
        if (file_exists($envFile)) {
            $envContents = file_get_contents($envFile);
            if (preg_match('/^DB_DRIVER=(\S+)/m', $envContents, $m)) {
                $dbDriver = $m[1];
            }
        }

        if ($dbDriver !== 'sqlite') {
            $this->comment("  Database driver: {$dbDriver} (configure in .env)");
            return;
        }

        $this->task('Creating SQLite database', function () use ($dbFile) {
            if (!file_exists($dbFile)) {
                touch($dbFile);
                return 'created';
            }
            return 'already exists';
        });
    }

    private function runMigrations(): void
    {
        $this->newLine();
        $this->task('Running migrations', function () {
            return $this->call('migrate');
        });
    }
}
