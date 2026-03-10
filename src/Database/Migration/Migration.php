<?php

declare(strict_types=1);

namespace Fw\Database\Migration;

use Fw\Database\Connection;
use RuntimeException;

abstract class Migration
{
    protected Connection $db;

    public function __construct(Connection $db)
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
     * Execute raw SQL.
     */
    protected function execute(string $sql): void
    {
        $this->db->getPdo()->exec($sql);
    }

    /**
     * Create a table.
     */
    protected function createTable(string $name, callable $callback): void
    {
        $blueprint = new Blueprint($name, $this->db->driver);
        $callback($blueprint);
        $this->execute($blueprint->toSql());
    }

    /**
     * Drop a table.
     */
    protected function dropTable(string $name): void
    {
        $this->execute('DROP TABLE IF EXISTS ' . $this->quote($name));
    }

    /**
     * Drop a table if it exists.
     */
    protected function dropTableIfExists(string $name): void
    {
        $this->dropTable($name);
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
        return $this->db->quoteIdentifier($identifier);
    }

    /**
     * Check if a table exists.
     */
    protected function tableExists(string $name): bool
    {
        $sql = match ($this->db->driver) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
            'mysql' => "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_NAME=?",
            'pgsql' => "SELECT tablename FROM pg_tables WHERE tablename=?",
            default => throw new RuntimeException("Unsupported driver: {$this->db->driver}"),
        };

        $result = $this->db->selectOne($sql, [$name]);
        return $result !== null;
    }
}
