<?php

declare(strict_types=1);

namespace Fw\Database\Migration;

use InvalidArgumentException;

final class Blueprint
{
    private string $table;

    private string $driver;

    private bool $alter;

    private array $columns = [];

    private array $indexes = [];

    private ?string $primaryKey = null;

    private array $foreignKeys = [];

    private array $dropColumns = [];

    private ?string $lastColumnName = null;

    public function __construct(string $table, string $driver = 'sqlite', bool $alter = false)
    {
        $this->table = $table;
        $this->driver = $driver;
        $this->alter = $alter;
    }

    public function id(string $name = 'id'): self
    {
        $this->primaryKey = $name;
        $type = match ($this->driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'mysql' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'BIGSERIAL PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY',
        };
        $this->columns[] = $this->quote($name) . " $type";
        $this->lastColumnName = $name;
        return $this;
    }

    public function string(string $name, int $length = 255): self
    {
        $this->columns[] = $this->quote($name) . " VARCHAR($length)";
        $this->lastColumnName = $name;
        return $this;
    }

    public function text(string $name): self
    {
        $this->columns[] = $this->quote($name) . ' TEXT';
        $this->lastColumnName = $name;
        return $this;
    }

    public function integer(string $name): self
    {
        $this->columns[] = $this->quote($name) . ' INTEGER';
        $this->lastColumnName = $name;
        return $this;
    }

    public function bigInteger(string $name): self
    {
        $type = $this->driver === 'sqlite' ? 'INTEGER' : 'BIGINT';
        $this->columns[] = $this->quote($name) . " $type";
        $this->lastColumnName = $name;
        return $this;
    }

    public function boolean(string $name): self
    {
        $type = match ($this->driver) {
            'sqlite', 'mysql' => 'TINYINT(1)',
            'pgsql' => 'BOOLEAN',
            default => 'TINYINT(1)',
        };
        $this->columns[] = $this->quote($name) . " $type";
        $this->lastColumnName = $name;
        return $this;
    }

    public function decimal(string $name, int $precision = 8, int $scale = 2): self
    {
        $this->columns[] = $this->quote($name) . " DECIMAL($precision, $scale)";
        $this->lastColumnName = $name;
        return $this;
    }

    public function json(string $name): self
    {
        $type = match ($this->driver) {
            'mysql' => 'JSON',
            default => 'TEXT',
        };
        $this->columns[] = $this->quote($name) . " $type";
        $this->lastColumnName = $name;
        return $this;
    }

    public function date(string $name): self
    {
        $this->columns[] = $this->quote($name) . ' DATE';
        $this->lastColumnName = $name;
        return $this;
    }

    public function datetime(string $name): self
    {
        $type = match ($this->driver) {
            'mysql' => 'DATETIME',
            'pgsql' => 'TIMESTAMP',
            default => 'DATETIME',
        };
        $this->columns[] = $this->quote($name) . " $type";
        $this->lastColumnName = $name;
        return $this;
    }

    public function timestamp(string $name): self
    {
        return $this->datetime($name);
    }

    public function timestamps(): self
    {
        $default = 'CURRENT_TIMESTAMP';
        $this->columns[] = $this->quote('created_at') . " DATETIME DEFAULT $default";
        $this->columns[] = $this->quote('updated_at') . " DATETIME DEFAULT $default";
        $this->lastColumnName = 'updated_at';
        return $this;
    }

    public function softDeletes(): self
    {
        $this->columns[] = $this->quote('deleted_at') . ' DATETIME NULL';
        $this->lastColumnName = 'deleted_at';
        return $this;
    }

    public function nullable(): self
    {
        $lastIndex = count($this->columns) - 1;
        if ($lastIndex >= 0 && !str_contains($this->columns[$lastIndex], 'PRIMARY KEY')) {
            $this->columns[$lastIndex] .= ' NULL';
        }
        return $this;
    }

    public function notNull(): self
    {
        $lastIndex = count($this->columns) - 1;
        if ($lastIndex >= 0 && !str_contains($this->columns[$lastIndex], 'PRIMARY KEY')) {
            $this->columns[$lastIndex] .= ' NOT NULL';
        }
        return $this;
    }

    public function default(mixed $value): self
    {
        $lastIndex = count($this->columns) - 1;
        if ($lastIndex >= 0) {
            if (is_string($value)) {
                $value = "'" . str_replace("'", "''", $value) . "'";
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif ($value === null) {
                $value = 'NULL';
            }
            $this->columns[$lastIndex] .= " DEFAULT $value";
        }
        return $this;
    }

    public function unique(string|array $columns = []): self
    {
        if (empty($columns)) {
            $lastIndex = count($this->columns) - 1;
            if ($lastIndex >= 0) {
                $this->columns[$lastIndex] .= ' UNIQUE';
            }
        } else {
            $cols = is_array($columns) ? $columns : [$columns];
            $colList = implode(', ', array_map(fn ($c) => $this->quote($c), $cols));
            $name = 'idx_' . $this->table . '_' . implode('_', $cols);
            $this->indexes[] = 'CREATE UNIQUE INDEX ' . $this->quote($name) . ' ON ' . $this->quote($this->table) . " ($colList)";
        }
        return $this;
    }

    public function index(string|array $columns, ?string $name = null): self
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $colList = implode(', ', array_map(fn ($c) => $this->quote($c), $cols));
        $name ??= 'idx_' . $this->table . '_' . implode('_', $cols);
        $this->indexes[] = 'CREATE INDEX ' . $this->quote($name) . ' ON ' . $this->quote($this->table) . " ($colList)";
        return $this;
    }

    public function foreignId(string $name): self
    {
        // Must match id() type exactly for foreign key compatibility
        $type = match ($this->driver) {
            'mysql' => 'BIGINT UNSIGNED',
            'pgsql' => 'BIGINT',
            default => 'INTEGER',
        };
        $this->columns[] = $this->quote($name) . " $type NOT NULL";
        $this->lastColumnName = $name;
        // Automatically create index for foreign key columns
        $this->index($name);
        return $this;
    }

    /**
     * Add a foreign key constraint, inferring the referenced table from the column name.
     *
     * Expects the last column to be a foreignId (e.g., user_id → references users.id).
     */
    public function constrained(?string $table = null): self
    {
        if ($this->lastColumnName === null) {
            throw new InvalidArgumentException('constrained() must be called after a column definition');
        }

        // Infer table name: user_id → users
        $referencedTable = $table ?? $this->inferTableFromColumn($this->lastColumnName);

        $fk = new ForeignKeyDefinition($this->table, $this->lastColumnName);
        $fk->references('id')->on($referencedTable);
        $this->foreignKeys[] = $fk;

        return $this;
    }

    /**
     * Set cascade on delete for the last foreign key.
     */
    public function cascadeOnDelete(): self
    {
        $lastFk = end($this->foreignKeys);
        if ($lastFk instanceof ForeignKeyDefinition) {
            $lastFk->onDelete('CASCADE');
        }
        return $this;
    }

    /**
     * Set null on delete for the last foreign key.
     */
    public function nullOnDelete(): self
    {
        $lastFk = end($this->foreignKeys);
        if ($lastFk instanceof ForeignKeyDefinition) {
            $lastFk->onDelete('SET NULL');
        }
        return $this;
    }

    public function foreign(string $column): ForeignKeyDefinition
    {
        $fk = new ForeignKeyDefinition($this->table, $column);
        $this->foreignKeys[] = $fk;
        return $fk;
    }

    /**
     * Mark a column for dropping (alter mode only).
     */
    public function dropColumn(string $name): self
    {
        $this->dropColumns[] = $name;
        return $this;
    }

    /**
     * Generate CREATE TABLE SQL.
     */
    public function toSql(): string
    {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $this->quote($this->table) . " (\n    ";
        $sql .= implode(",\n    ", $this->columns);

        foreach ($this->foreignKeys as $fk) {
            $sql .= ",\n    " . $fk->toSql($this->driver);
        }

        $sql .= "\n)";

        if (!empty($this->indexes)) {
            $sql .= ";\n" . implode(";\n", $this->indexes);
        }

        return $sql;
    }

    /**
     * Generate ALTER TABLE SQL for dropping columns.
     */
    public function toAlterSql(): string
    {
        $statements = $this->toAlterStatements();
        return implode(";\n", $statements);
    }

    /**
     * Generate individual ALTER TABLE statements for alter mode.
     *
     * @return array<string>
     */
    public function toAlterStatements(): array
    {
        $statements = [];
        $quotedTable = $this->quote($this->table);

        // Add columns
        foreach ($this->columns as $column) {
            $statements[] = "ALTER TABLE $quotedTable ADD COLUMN $column";
        }

        // Drop columns
        foreach ($this->dropColumns as $name) {
            $statements[] = "ALTER TABLE $quotedTable DROP COLUMN " . $this->quote($name);
        }

        // Add indexes
        foreach ($this->indexes as $indexSql) {
            $statements[] = $indexSql;
        }

        // Add foreign keys
        foreach ($this->foreignKeys as $fk) {
            $statements[] = "ALTER TABLE $quotedTable ADD " . $fk->toSql($this->driver);
        }

        return $statements;
    }

    /**
     * Infer table name from a foreign key column name (e.g., user_id → users).
     */
    private function inferTableFromColumn(string $column): string
    {
        if (!str_ends_with($column, '_id')) {
            throw new InvalidArgumentException(
                "Cannot infer table from column '$column'. Expected format: <table>_id"
            );
        }

        $singular = substr($column, 0, -3);

        // Simple pluralization
        if (str_ends_with($singular, 'y') && !str_ends_with($singular, 'ey')) {
            return substr($singular, 0, -1) . 'ies';
        }
        if (str_ends_with($singular, 's') || str_ends_with($singular, 'x') || str_ends_with($singular, 'ch') || str_ends_with($singular, 'sh')) {
            return $singular . 'es';
        }

        return $singular . 's';
    }

    private function quote(string $identifier): string
    {
        return match ($this->driver) {
            'mysql' => '`' . str_replace('`', '``', $identifier) . '`',
            default => '"' . str_replace('"', '""', $identifier) . '"',
        };
    }
}

final class ForeignKeyDefinition
{
    private const array ALLOWED_FK_ACTIONS = ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION', 'SET DEFAULT'];

    private string $table;

    private string $column;

    private string $referencesTable = '';

    private string $referencesColumn = 'id';

    private string $onDelete = 'CASCADE';

    private string $onUpdate = 'CASCADE';

    public function __construct(string $table, string $column)
    {
        $this->table = $table;
        $this->column = $column;
    }

    public function references(string $column): self
    {
        $this->referencesColumn = $column;
        return $this;
    }

    public function on(string $table): self
    {
        $this->referencesTable = $table;
        return $this;
    }

    public function onDelete(string $action): self
    {
        $action = strtoupper($action);
        if (!in_array($action, self::ALLOWED_FK_ACTIONS, true)) {
            throw new InvalidArgumentException(
                "Invalid foreign key action: {$action}. Allowed: " . implode(', ', self::ALLOWED_FK_ACTIONS)
            );
        }
        $this->onDelete = $action;
        return $this;
    }

    public function onUpdate(string $action): self
    {
        $action = strtoupper($action);
        if (!in_array($action, self::ALLOWED_FK_ACTIONS, true)) {
            throw new InvalidArgumentException(
                "Invalid foreign key action: {$action}. Allowed: " . implode(', ', self::ALLOWED_FK_ACTIONS)
            );
        }
        $this->onUpdate = $action;
        return $this;
    }

    public function cascadeOnDelete(): self
    {
        return $this->onDelete('CASCADE');
    }

    public function nullOnDelete(): self
    {
        return $this->onDelete('SET NULL');
    }

    public function toSql(string $driver = 'sqlite'): string
    {
        return sprintf(
            'FOREIGN KEY (%s) REFERENCES %s(%s) ON DELETE %s ON UPDATE %s',
            $this->quote($this->column, $driver),
            $this->quote($this->referencesTable, $driver),
            $this->quote($this->referencesColumn, $driver),
            $this->onDelete,
            $this->onUpdate
        );
    }

    private function quote(string $identifier, string $driver): string
    {
        return match ($driver) {
            'mysql' => '`' . str_replace('`', '``', $identifier) . '`',
            default => '"' . str_replace('"', '""', $identifier) . '"',
        };
    }
}
