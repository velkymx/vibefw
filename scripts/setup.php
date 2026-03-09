<?php

/**
 * VibeFW Project Setup Script
 *
 * This script runs automatically after `composer create-project` and can also
 * be run manually via `php scripts/setup.php` for cloned repos.
 *
 * It is standalone — no autoloader or framework dependencies required.
 *
 * What it does:
 *   1. Copies .env.example → .env (if .env doesn't exist)
 *   2. Generates APP_KEY and QUEUE_SECRET_KEY
 *   3. Creates required storage directories
 *   4. Creates the SQLite database file
 *   5. Prints a welcome message with next steps
 *
 * Note: Migrations are NOT run here. They are installed and run by `php fw make:spa`.
 */

$basePath = dirname(__DIR__);

// ANSI color helpers (works on all modern terminals)
$green  = static fn(string $text): string => "\033[32m{$text}\033[0m";
$yellow = static fn(string $text): string => "\033[33m{$text}\033[0m";
$cyan   = static fn(string $text): string => "\033[36m{$text}\033[0m";
$bold   = static fn(string $text): string => "\033[1m{$text}\033[0m";
$dim    = static fn(string $text): string => "\033[2m{$text}\033[0m";

echo "\n";
echo $bold("  ⚡ VibeFW Project Setup\n");
echo $dim("  ─────────────────────────────────\n");
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// 1. Environment file
// ─────────────────────────────────────────────────────────────────────────────
$envFile = $basePath . '/.env';
$envExample = $basePath . '/.env.example';

if (file_exists($envFile)) {
    echo "  {$green('✓')} .env already exists " . $dim('(skipped)') . "\n";
} elseif (!file_exists($envExample)) {
    echo "  {$yellow('!')} .env.example not found — skipping .env creation\n";
} else {
    $env = file_get_contents($envExample);

    // Generate cryptographic keys
    $appKey = bin2hex(random_bytes(32));
    $queueKey = bin2hex(random_bytes(32));

    $env = preg_replace('/^APP_KEY=$/m', "APP_KEY={$appKey}", $env);
    $env = preg_replace('/^QUEUE_SECRET_KEY=$/m', "QUEUE_SECRET_KEY={$queueKey}", $env);

    file_put_contents($envFile, $env);
    echo "  {$green('✓')} Created .env with secure keys\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. Storage directories
// ─────────────────────────────────────────────────────────────────────────────
$dirs = [
    'storage/cache',
    'storage/cache/ratelimit',
    'storage/cache/views',
    'storage/logs',
    'storage/queue',
];

$createdDirs = 0;
foreach ($dirs as $dir) {
    $fullPath = $basePath . '/' . $dir;
    if (!is_dir($fullPath)) {
        mkdir($fullPath, 0750, true);
        $createdDirs++;
    }
    // Ensure .gitkeep exists
    $gitkeep = $fullPath . '/.gitkeep';
    if (!file_exists($gitkeep)) {
        touch($gitkeep);
    }
}

if ($createdDirs > 0) {
    echo "  {$green('✓')} Created {$createdDirs} storage directories\n";
} else {
    echo "  {$green('✓')} Storage directories exist " . $dim('(skipped)') . "\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. SQLite database
// ─────────────────────────────────────────────────────────────────────────────
$dbFile = $basePath . '/storage/database.sqlite';

// Read DB_DRIVER from .env to check if SQLite is configured
$dbDriver = 'sqlite'; // default
if (file_exists($envFile)) {
    $envContents = file_get_contents($envFile);
    if (preg_match('/^DB_DRIVER=(\S+)/m', $envContents, $m)) {
        $dbDriver = $m[1];
    }
}

if ($dbDriver === 'sqlite') {
    if (!file_exists($dbFile)) {
        touch($dbFile);
        echo "  {$green('✓')} Created SQLite database\n";
    } else {
        echo "  {$green('✓')} SQLite database exists " . $dim('(skipped)') . "\n";
    }
} else {
    echo "  {$dim('·')} Database driver: {$dbDriver} " . $dim('(configure in .env)') . "\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. Welcome message
// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
echo $dim("  ─────────────────────────────────\n");
echo "\n";
echo "  {$green('✓')} " . $bold("Project ready!") . "\n";
echo "\n";
echo "  Next steps:\n";
echo "\n";
echo "    {$cyan('php fw serve')}            Start the dev server\n";
echo "    {$cyan('php fw make:spa')}         Add a Vue 3 + VibeUI frontend\n";
echo "    {$cyan('php fw make:model Post -m')}  Create a model with migration\n";
echo "\n";
echo "  Docs: {$dim('https://github.com/velkymx/vibefw')}\n";
echo "\n";
