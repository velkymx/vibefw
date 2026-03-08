<?php

declare(strict_types=1);

namespace Fw\Database\Migration;

final class Blueprint
{
    private string $table;

    private string $driver;

    private array $columns = [];

    private array $indexes = [];

    private ?string $primaryKey = null;

    private array $foreignKeys = [];

    public function __construct(string $table, string $driver = 'sqlite')
    {
        $this->table = $table;
        $this->driver = $driver;
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
        return $this;
    }

    public function string(string $name, int $length = 255): self
    {
        $this->columns[] = $this->quote($name) . " VARCHAR($length)";
        return $this;
    }

    public function text(string $name): self
    {
        $this->columns[] = $this->quote($name) . ' TEXT';
        return $this;
    }

    public function integer(string $name): self
    {
        $this->columns[] = $this->quote($name) . ' INTEGER';
        return $this;
    }

    public function bigInteger(string $name): self
    {
        $type = $this->driver === 'sqlite' ? 'INTEGER' : 'BIGINT';
        $this->columns[] = $this->quote($name) . " $type";
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
        return $this;
    }

    public function softDeletes(): self
    {
        $this->columns[] = $this->quote('deleted_at') . ' DATETIME NULL';
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
        // Automatically create index for foreign key columns — required for
        // efficient JOIN performance in belongsTo/hasMany relationships.
        $this->index($name);
        return $this;
    }

    public function foreign(string $column): ForeignKeyDefinition
    {
        $fk = new ForeignKeyDefinition($this->table, $column);
        $this->foreignKeys[] = $fk;
        return $fk;
    }

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
        $this->onDelete = strtoupper($action);
        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = strtoupper($action);
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
