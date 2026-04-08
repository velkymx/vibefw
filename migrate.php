#!/usr/bin/env php
<?php

declare(strict_types=1);

define('BASE_PATH', __DIR__);

require BASE_PATH . '/vendor/autoload.php';

use Fw\Core\Env;
use Fw\Database\Connection;
use Fw\Database\Migration\Migrator;

// Load environment variables
Env::load(BASE_PATH . '/.env');

$config = require BASE_PATH . '/config/database.php';
$db = Connection::getInstance($config);

$migrator = new Migrator($db, BASE_PATH . '/database/migrations');

$command = $argv[1] ?? 'migrate';
$option = $argv[2] ?? null;

echo "Fw Migration Tool\n";
echo str_repeat('-', 40) . "\n\n";

match ($command) {
    'migrate', 'up' => doMigrate($migrator),
    'rollback', 'down' => doRollback($migrator, $option),
    'reset' => doReset($migrator),
    'refresh' => doRefresh($migrator),
    'status' => doStatus($migrator),
    'seed' => doSeed($db),
    'fresh' => doFresh($migrator, $db),
    'help', '--help', '-h' => showHelp(),
    default => showHelp(),
};

function doMigrate(Migrator $migrator): void
{
    echo "Running migrations...\n\n";

    $ran = $migrator->run();

    if (empty($ran)) {
        echo "Nothing to migrate.\n";
        return;
    }

    foreach ($ran as $migration) {
        $name = pathinfo($migration, PATHINFO_FILENAME);
        echo "  ✓ $name\n";
    }

    echo "\nMigrated " . count($ran) . " migration(s).\n";
}

function doRollback(Migrator $migrator, ?string $steps): void
{
    $steps = $steps !== null ? (int) $steps : null;

    echo "Rolling back" . ($steps ? " $steps migration(s)" : " last batch") . "...\n\n";

    $rolledBack = $migrator->rollback($steps);

    if (empty($rolledBack)) {
        echo "Nothing to rollback.\n";
        return;
    }

    foreach ($rolledBack as $migration) {
        echo "  ↩ $migration\n";
    }

    echo "\nRolled back " . count($rolledBack) . " migration(s).\n";
}

function doReset(Migrator $migrator): void
{
    echo "Resetting all migrations...\n\n";

    $rolledBack = $migrator->reset();

    if (empty($rolledBack)) {
        echo "Nothing to reset.\n";
        return;
    }

    foreach ($rolledBack as $migration) {
        echo "  ↩ $migration\n";
    }

    echo "\nReset " . count($rolledBack) . " migration(s).\n";
}

function doRefresh(Migrator $migrator): void
{
    echo "Refreshing database...\n\n";

    $ran = $migrator->refresh();

    foreach ($ran as $migration) {
        $name = pathinfo($migration, PATHINFO_FILENAME);
        echo "  ✓ $name\n";
    }

    echo "\nRefreshed " . count($ran) . " migration(s).\n";
}

function doFresh(Migrator $migrator, Connection $db): void
{
    echo "Dropping all tables and re-running migrations...\n\n";

    // Drop all tables
    $tables = match ($db->driver) {
        'sqlite' => $db->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"),
        'mysql' => $db->select("SHOW TABLES"),
        'pgsql' => $db->select("SELECT tablename as name FROM pg_tables WHERE schemaname = 'public'"),
        default => [],
    };

    // Disable foreign key checks for MySQL during drop
    if ($db->driver === 'mysql') {
        $db->getPdo()->exec("SET FOREIGN_KEY_CHECKS = 0");
    }

    foreach ($tables as $table) {
        $name = $table['name'] ?? array_values($table)[0];
        $quotedName = match ($db->driver) {
            'mysql' => "`$name`",
            default => "\"$name\"",
        };
        $db->getPdo()->exec("DROP TABLE IF EXISTS $quotedName");
        echo "  ✗ Dropped $name\n";
    }

    // Re-enable foreign key checks
    if ($db->driver === 'mysql') {
        $db->getPdo()->exec("SET FOREIGN_KEY_CHECKS = 1");
    }

    echo "\n";

    // Recreate migrations table and run migrations
    $migrator->ensureMigrationsTable();
    $ran = $migrator->run();

    foreach ($ran as $migration) {
        $name = pathinfo($migration, PATHINFO_FILENAME);
        echo "  ✓ $name\n";
    }

    echo "\nFresh migration complete.\n";
}

function doStatus(Migrator $migrator): void
{
    echo "Migration Status\n\n";

    $status = $migrator->status();

    if (empty($status)) {
        echo "No migrations found.\n";
        return;
    }

    foreach ($status as $item) {
        $icon = $item['status'] === 'Ran' ? '✓' : '○';
        echo "  $icon {$item['migration']} [{$item['status']}]\n";
    }
}

function doSeed(Connection $db): void
{
    echo "Seeding database...\n\n";

    $count = $db->selectOne("SELECT COUNT(*) as count FROM users")['count'] ?? 0;

    if ($count > 0) {
        echo "Database already has data. Use 'php migrate.php fresh' to reset first.\n";
        return;
    }

    // Seed users
    $db->insert('users', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => password_hash('password', PASSWORD_ARGON2ID),
    ]);

    $db->insert('users', [
        'name' => 'Jane Smith',
        'email' => 'jane@example.com',
        'password' => password_hash('password', PASSWORD_ARGON2ID),
    ]);

    $db->insert('users', [
        'name' => 'Bob Wilson',
        'email' => 'bob@example.com',
        'password' => password_hash('password', PASSWORD_ARGON2ID),
    ]);

    echo "  ✓ Seeded 3 users\n";

    // Seed posts
    $db->insert('posts', [
        'user_id' => 1,
        'title' => 'Getting Started with VibeFw Framework',
        'slug' => 'getting-started-fw-framework',
        'content' => 'Learn how to build fast and secure PHP applications with the VibeFw Framework.',
        'published_at' => date('Y-m-d H:i:s'),
    ]);

    $db->insert('posts', [
        'user_id' => 1,
        'title' => 'Understanding the MVC Pattern',
        'slug' => 'understanding-mvc-pattern',
        'content' => 'The Model-View-Controller pattern separates your application into three components.',
        'published_at' => date('Y-m-d H:i:s'),
    ]);

    $db->insert('posts', [
        'user_id' => 2,
        'title' => 'Security Best Practices',
        'slug' => 'security-best-practices',
        'content' => 'Always sanitize input, use prepared statements, and validate CSRF tokens.',
        'published_at' => date('Y-m-d H:i:s'),
    ]);

    echo "  ✓ Seeded 3 posts\n";
    echo "\nSeeding complete.\n";
}

function showHelp(): void
{
    echo <<<HELP
    Usage: php migrate.php <command> [options]

    Commands:
      migrate, up       Run all pending migrations
      rollback [n]      Rollback the last batch (or n migrations)
      reset             Rollback all migrations
      refresh           Reset and re-run all migrations
      fresh             Drop all tables and re-run migrations
      status            Show migration status
      seed              Seed the database with sample data
      help              Show this help message

    Examples:
      php migrate.php migrate      # Run pending migrations
      php migrate.php rollback     # Rollback last batch
      php migrate.php rollback 2   # Rollback last 2 migrations
      php migrate.php fresh        # Drop all and re-migrate
      php migrate.php seed         # Add sample data

    HELP;
}
