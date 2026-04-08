<?php

declare(strict_types=1);

namespace Fw\Database\Migration;

use Fw\Database\Connection;
use RuntimeException;

abstract class Migration
{
    protected ?Connection $db = null;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db;
    }

    public function setConnection(Connection $db): void
    {
        $this->db = $db;
    }

    /**
     * Run the migration.
     */
    abstract public function up(): void;

    /**
     * Reverse the migration.
     */
    abstract public function down(): void;

    /**
     * Get the database connection, ensuring it is set.
     */
    protected function connection(): Connection
    {
        if ($this->db === null) {
            throw new RuntimeException('Migration has no database connection. This is a framework bug.');
        }

        return $this->db;
    }

    /**
     * Execute raw SQL.
     */
    protected function execute(string $sql): void
    {
        $this->connection()->getPdo()->exec($sql);
    }

    /**
     * Create a table.
     */
    protected function create(string $name, callable $callback): void
    {
        $blueprint = new Blueprint($name, $this->connection()->driver);
        $callback($blueprint);
        $this->execute($blueprint->toSql());
    }

    /**
     * Create a table (alias for create).
     */
    protected function createTable(string $name, callable $callback): void
    {
        $this->create($name, $callback);
    }

    /**
     * Alter an existing table (add or drop columns).
     */
    protected function table(string $name, callable $callback): void
    {
        $blueprint = new Blueprint($name, $this->connection()->driver, alter: true);
        $callback($blueprint);

        foreach ($blueprint->toAlterStatements() as $sql) {
            $this->execute($sql);
        }
    }

    /**
     * Drop a table if it exists.
     */
    protected function drop(string $name): void
    {
        $this->execute('DROP TABLE IF EXISTS ' . $this->quote($name));
    }

    /**
     * Drop a table if it exists (alias for drop).
     */
    protected function dropTable(string $name): void
    {
        $this->drop($name);
    }

    /**
     * Drop a table if it exists.
     */
    protected function dropTableIfExists(string $name): void
    {
        $this->drop($name);
    }

    /**
     * Rename a table.
     */
    protected function renameTable(string $from, string $to): void
    {
        $this->execute('ALTER TABLE ' . $this->quote($from) . ' RENAME TO ' . $this->quote($to));
    }

    /**
     * Quote an identifier for the current database driver.
     */
    protected function quote(string $identifier): string
    {
        return $this->connection()->quoteIdentifier($identifier);
    }

    /**
     * Check if a table exists.
     */
    protected function tableExists(string $name): bool
    {
        $sql = match ($this->connection()->driver) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
            'mysql' => "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_NAME=?",
            'pgsql' => "SELECT tablename FROM pg_tables WHERE tablename=?",
            default => throw new RuntimeException("Unsupported driver: {$this->connection()->driver}"),
        };

        $result = $this->connection()->selectOne($sql, [$name]);
        return $result !== null;
    }
}
