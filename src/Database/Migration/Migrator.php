<?php

declare(strict_types=1);

namespace Fw\Database\Migration;

use Fw\Database\Connection;
use RuntimeException;
use Throwable;

final class Migrator
{
    private Connection $db;

    private string $migrationsPath;

    private string $table = 'migrations';

    public function __construct(Connection $db, string $migrationsPath)
    {
        $this->db = $db;
        $this->migrationsPath = rtrim($migrationsPath, '/');
        $this->ensureMigrationsTable();
    }

    public function ensureMigrationsTable(): void
    {
        $quoted = $this->quoteTable();

        $sql = match ($this->db->driver) {
            'sqlite' => <<<SQL
                CREATE TABLE IF NOT EXISTS {$quoted} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    migration VARCHAR(255) NOT NULL,
                    batch INTEGER NOT NULL,
                    executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
                SQL,
            'mysql' => <<<SQL
                CREATE TABLE IF NOT EXISTS {$quoted} (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    migration VARCHAR(255) NOT NULL,
                    batch INT NOT NULL,
                    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
                SQL,
            'pgsql' => <<<SQL
                CREATE TABLE IF NOT EXISTS {$quoted} (
                    id BIGSERIAL PRIMARY KEY,
                    migration VARCHAR(255) NOT NULL,
                    batch INT NOT NULL,
                    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
                SQL,
            default => throw new RuntimeException("Unsupported driver: {$this->db->driver}"),
        };

        $this->db->getPdo()->exec($sql);
    }

    public function run(): array
    {
        $migrations = $this->getPendingMigrations();

        if (empty($migrations)) {
            return [];
        }

        $batch = $this->getNextBatchNumber();
        $ran = [];

        foreach ($migrations as $file) {
            $this->runMigration($file, $batch);
            $ran[] = $file;
        }

        return $ran;
    }

    public function rollback(?int $steps = null): array
    {
        $migrations = $this->getMigrationsToRollback($steps);

        if (empty($migrations)) {
            return [];
        }

        $rolledBack = [];

        foreach ($migrations as $migration) {
            $this->rollbackMigration($migration['migration']);
            $rolledBack[] = $migration['migration'];
        }

        return $rolledBack;
    }

    public function reset(): array
    {
        $migrations = $this->db->select(
            'SELECT migration FROM ' . $this->quoteTable() . ' ORDER BY batch DESC, id DESC'
        );

        $rolledBack = [];

        foreach ($migrations as $migration) {
            $this->rollbackMigration($migration['migration']);
            $rolledBack[] = $migration['migration'];
        }

        return $rolledBack;
    }

    public function refresh(): array
    {
        $this->reset();
        return $this->run();
    }

    public function status(): array
    {
        $ran = $this->getRanMigrations();
        $files = $this->getMigrationFiles();
        $status = [];

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $status[] = [
                'migration' => $name,
                'status' => in_array($name, $ran, true) ? 'Ran' : 'Pending',
            ];
        }

        return $status;
    }

    /**
     * Get list of ran migration names.
     *
     * @return array<string>
     */
    public function ran(): array
    {
        return $this->getRanMigrations();
    }

    /**
     * Get list of pending migration names.
     *
     * @return array<string>
     */
    public function pending(): array
    {
        $ran = $this->getRanMigrations();
        $files = $this->getMigrationFiles();
        $pending = [];

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if (!in_array($name, $ran, true)) {
                $pending[] = $name;
            }
        }

        return $pending;
    }

    /**
     * Alias for run() - runs pending migrations.
     *
     * @return array<string>
     */
    public function migrate(): array
    {
        return $this->run();
    }

    private function quoteTable(): string
    {
        return $this->db->quoteIdentifier($this->table);
    }

    private function getPendingMigrations(): array
    {
        $ran = $this->getRanMigrations();
        $files = $this->getMigrationFiles();
        $pending = [];

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if (!in_array($name, $ran, true)) {
                $pending[] = $file;
            }
        }

        return $pending;
    }

    private function getRanMigrations(): array
    {
        $results = $this->db->select(
            'SELECT migration FROM ' . $this->quoteTable() . ' ORDER BY batch, id'
        );

        return array_column($results, 'migration');
    }

    private function getMigrationFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath . '/*.php') ?: [];
        sort($files);

        return $files;
    }

    private function getNextBatchNumber(): int
    {
        $result = $this->db->selectOne(
            'SELECT MAX(batch) as batch FROM ' . $this->quoteTable()
        );

        return ($result['batch'] ?? 0) + 1;
    }

    private function getMigrationsToRollback(?int $steps): array
    {
        if ($steps === null) {
            $batch = $this->db->selectOne(
                'SELECT MAX(batch) as batch FROM ' . $this->quoteTable()
            );

            if (!$batch || $batch['batch'] === null) {
                return [];
            }

            return $this->db->select(
                'SELECT migration FROM ' . $this->quoteTable() . ' WHERE batch = ? ORDER BY id DESC',
                [$batch['batch']]
            );
        }

        // Inline the LIMIT as (int): $steps is a typed ?int parameter,
        // and some PDO drivers reject bound integers in LIMIT clauses.
        return $this->db->select(
            'SELECT migration FROM ' . $this->quoteTable()
            . ' ORDER BY batch DESC, id DESC LIMIT ' . (int) $steps
        );
    }

    private function runMigration(string $file, int $batch): void
    {
        $migration = $this->resolveMigration($file);

        // Note: MySQL auto-commits DDL statements (CREATE TABLE, etc.)
        // so transactions don't help here. We run without transaction wrapper
        // for DDL compatibility across all drivers.
        try {
            $migration->up();

            $name = pathinfo($file, PATHINFO_FILENAME);
            $this->db->insert($this->table, [
                'migration' => $name,
                'batch' => $batch,
            ]);
        } catch (Throwable $e) {
            // Migration failed - for MySQL, DDL changes can't be rolled back
            // The error will be thrown and the migration won't be recorded
            throw $e;
        }
    }

    private function rollbackMigration(string $name): void
    {
        $file = $this->migrationsPath . '/' . $name . '.php';

        if (!file_exists($file)) {
            throw new RuntimeException("Migration file not found: $file");
        }

        $migration = $this->resolveMigration($file);

        // Note: MySQL auto-commits DDL statements, so no transaction wrapper
        try {
            $migration->down();

            $this->db->query(
                'DELETE FROM ' . $this->quoteTable() . ' WHERE migration = ?',
                [$name]
            );
        } catch (Throwable $e) {
            throw $e;
        }
    }

    private function resolveMigration(string $file): Migration
    {
        // Validate the file is within the migrations directory to prevent LFI
        $realFile = realpath($file);
        $realBase = realpath($this->migrationsPath);

        if ($realFile === false || $realBase === false) {
            throw new RuntimeException("Migration file not found: $file");
        }

        if (!str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR) && $realFile !== $realBase) {
            throw new RuntimeException("Migration file outside migrations directory: $file");
        }

        if (!str_ends_with($realFile, '.php')) {
            throw new RuntimeException("Migration must be a PHP file: $file");
        }

        $result = require $realFile;

        // Anonymous class pattern: file returns a Migration instance
        if ($result instanceof Migration) {
            $result->setConnection($this->db);
            return $result;
        }

        // Named class pattern: resolve class by converting filename to PascalCase
        $name = pathinfo($file, PATHINFO_FILENAME);
        $className = $this->getClassName($name);

        if (!class_exists($className)) {
            throw new RuntimeException("Migration class not found: $className in $file");
        }

        return new $className($this->db);
    }

    private function getClassName(string $name): string
    {
        $parts = explode('_', $name);
        array_shift($parts);

        return implode('', array_map('ucfirst', $parts));
    }
}
