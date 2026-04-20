<?php

declare(strict_types=1);

namespace Fw\Database;

use Fw\Support\Option;
use InvalidArgumentException;
use LogicException;

/**
 * Fluent query builder for database operations.
 *
 * This builder is immutable - each method returns a new cloned instance.
 */
final class QueryBuilder
{
    private const array ALLOWED_OPERATORS = [
        '=', '!=', '<>', '<', '>', '<=', '>=',
        'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE',
        'REGEXP', 'NOT REGEXP', 'RLIKE',
        '&', '|', '^', '<<', '>>',
    ];

    private const int MAX_IDENTIFIER_LENGTH = 255;

    private Connection $connection;

    private string $table = '';

    private array $columns = ['*'];

    private array $wheres = [];

    private array $bindings = [];

    private array $joins = [];

    private array $orderBy = [];

    private array $groupBy = [];

    private ?int $limit = null;

    private ?int $offset = null;

    private bool $distinct = false;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function clone(): self
    {
        return clone $this;
    }

    public function table(string $table): self
    {
        $clone = clone $this;
        $clone->table = $table;
        return $clone;
    }

    public function select(string|array $columns = ['*']): self
    {
        $cols = is_array($columns) ? $columns : func_get_args();
        foreach ($cols as $column) {
            $this->validateIdentifier($column, 'column');
        }

        $clone = clone $this;
        $clone->columns = $cols;
        return $clone;
    }

    public function distinct(): self
    {
        $clone = clone $this;
        $clone->distinct = true;
        return $clone;
    }

    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->addWhere('AND', $column, $operator, $value);
    }

    public function orWhere(string $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->addWhere('OR', $column, $operator, $value);
    }

    private function addWhere(string $boolean, string $column, mixed $operator, mixed $value): self
    {
        $explicitOperator = is_string($operator) && in_array($operator, self::ALLOWED_OPERATORS, true);
        if ($value === null && !$explicitOperator) {
            $value = $operator;
            $operator = '=';
        }
        $this->validateOperator($operator);

        if ($value === null) {
            if ($operator === '=') {
                $clone = clone $this;
                $clone->wheres[] = [
                    'type' => 'null',
                    'column' => $column,
                    'boolean' => $boolean,
                ];
                return $clone;
            }
            if ($operator === '!=' || $operator === '<>') {
                $clone = clone $this;
                $clone->wheres[] = [
                    'type' => 'notNull',
                    'column' => $column,
                    'boolean' => $boolean,
                ];
                return $clone;
            }
            throw new InvalidArgumentException("Operator '{$operator}' cannot be used with NULL value.");
        }

        $clone = clone $this;
        $clone->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'boolean' => $boolean,
        ];
        $clone->bindings[] = $value;
        return $clone;
    }

    public function whereIn(string $column, array $values): self
    {
        $clone = clone $this;
        $clone->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'count' => count($values),
            'boolean' => 'AND',
        ];
        $clone->bindings = array_merge($clone->bindings, $values);
        return $clone;
    }

    public function whereNull(string $column): self
    {
        $clone = clone $this;
        $clone->wheres[] = [
            'type' => 'null',
            'column' => $column,
            'boolean' => 'AND',
        ];
        return $clone;
    }

    public function whereNotNull(string $column): self
    {
        $clone = clone $this;
        $clone->wheres[] = [
            'type' => 'notNull',
            'column' => $column,
            'boolean' => 'AND',
        ];
        return $clone;
    }

    public function whereBetween(string $column, mixed $min, mixed $max): self
    {
        $clone = clone $this;
        $clone->wheres[] = [
            'type' => 'between',
            'column' => $column,
            'boolean' => 'AND',
        ];
        $clone->bindings[] = $min;
        $clone->bindings[] = $max;
        return $clone;
    }

    public function join(string $table, string $first, string $operator, string $second): self
    {
        if ($this->limit !== null) {
            throw new LogicException('Cannot add a join after limit has been set. Call join() before limit().');
        }
        $this->validateOperator($operator);
        $clone = clone $this;
        $clone->joins[] = [
            'type' => 'INNER',
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
        ];
        return $clone;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        if ($this->limit !== null) {
            throw new LogicException('Cannot add a join after limit has been set. Call join() before limit().');
        }
        $this->validateOperator($operator);
        $clone = clone $this;
        $clone->joins[] = [
            'type' => 'LEFT',
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
        ];
        return $clone;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->validateIdentifier($column, 'column');
        $dir = strtoupper($direction);
        if (!in_array($dir, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException("Invalid ORDER BY direction: '{$direction}'");
        }

        $clone = clone $this;
        $clone->orderBy[] = [$column, $dir];
        return $clone;
    }

    public function groupBy(string|array $columns): self
    {
        $cols = is_array($columns) ? $columns : func_get_args();
        foreach ($cols as $column) {
            $this->validateIdentifier($column, 'column');
        }

        $clone = clone $this;
        $clone->groupBy = $cols;
        return $clone;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('LIMIT must be non-negative');
        }
        $clone = clone $this;
        $clone->limit = $limit;
        return $clone;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('OFFSET must be non-negative');
        }
        $clone = clone $this;
        $clone->offset = $offset;
        return $clone;
    }

    public function get(): array
    {
        $this->requireTable();
        [$sql, $bindings] = $this->toSql();
        return $this->connection->select($sql, $bindings);
    }

    /**
     * @return Option<array<string, mixed>>
     */
    public function first(): Option
    {
        $this->requireTable();
        $result = $this->limit(1)->get();
        return isset($result[0]) ? Option::some($result[0]) : Option::none();
    }

    /**
     * @return Option<array<string, mixed>>
     */
    public function find(int|string $id, string $column = 'id'): Option
    {
        // Reject empty string early — avoids WHERE id = '' which can silently
        // match unexpected rows on DBs that coerce '' to 0.
        if ($id === '') {
            return Option::none();
        }

        return $this->where($column, $id)->first();
    }

    /**
     * COUNT rows. `$column = '*'` counts every row; a named column skips NULLs.
     * `$distinct = true` emits COUNT(DISTINCT column); the outer DISTINCT flag from
     * ->distinct() is always stripped here — aggregates return a single row and a
     * leaked SELECT DISTINCT would silently change semantics.
     */
    public function count(string $column = '*', bool $distinct = false): int
    {
        $this->requireTable();
        $expr = $this->aggregateExpr('COUNT', $column, $distinct);
        $result = $this->aggregateClone($expr)->first()->unwrapOr([]);
        return (int) ($result['aggregate'] ?? 0);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function sum(string $column, bool $distinct = false): float
    {
        $expr = $this->aggregateExpr('SUM', $column, $distinct);
        $result = $this->aggregateClone($expr)->first()->unwrapOr([]);
        return (float) ($result['aggregate'] ?? 0);
    }

    public function avg(string $column, bool $distinct = false): float
    {
        $expr = $this->aggregateExpr('AVG', $column, $distinct);
        $result = $this->aggregateClone($expr)->first()->unwrapOr([]);
        return (float) ($result['aggregate'] ?? 0);
    }

    public function max(string $column): mixed
    {
        $expr = $this->aggregateExpr('MAX', $column, false);
        $result = $this->aggregateClone($expr)->first()->unwrapOr([]);
        return $result['aggregate'] ?? null;
    }

    public function min(string $column): mixed
    {
        $expr = $this->aggregateExpr('MIN', $column, false);
        $result = $this->aggregateClone($expr)->first()->unwrapOr([]);
        return $result['aggregate'] ?? null;
    }

    private function aggregateExpr(string $fn, string $column, bool $distinct): string
    {
        $inner = $column === '*' ? '*' : $this->quoteIdentifier($column);
        $prefix = $distinct && $column !== '*' ? 'DISTINCT ' : '';
        return $fn . '(' . $prefix . $inner . ') as aggregate';
    }

    private function aggregateClone(string $expr): self
    {
        $clone = clone $this;
        $clone->columns = [$expr];
        $clone->distinct = false;
        return $clone;
    }

    /**
     * Paginate results.
     *
     * NOTE: count() and get() are two separate queries. Under concurrent inserts
     * or deletes the total count may not exactly match the number of items on the
     * last page (e.g. total=16 per_page=15 last_page=2 but only 1 item on page 2
     * because a concurrent insert happened between the two queries). For strict
     * consistency wrap calls in a REPEATABLE READ transaction at the call site.
     */
    public function paginate(int $perPage = 15, int $page = 1): array
    {
        if ($perPage <= 0) {
            throw new InvalidArgumentException('perPage must be greater than 0');
        }

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $total = $this->count();
        $items = $this->limit($perPage)->offset($offset)->get();

        return [
            'items' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage),
        ];
    }

    public function insert(array $data): int
    {
        $this->requireTable();
        return $this->connection->insert($this->table, $data);
    }

    public function update(array $data): int
    {
        $this->requireTable();

        if (empty($this->wheres)) {
            throw new \LogicException(
                'Refusing mass update on "' . $this->table . '" without a WHERE clause. '
                . 'Add ->where() to scope the update, or use ->where(\'1\', \'=\', \'1\') for an intentional bulk update.'
            );
        }

        $set = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set[] = $this->quoteIdentifier($column) . ' = ?';
            $params[] = $value;
        }
        $sql = sprintf('UPDATE %s SET %s%s', $this->quoteIdentifier($this->table), implode(', ', $set), $this->compileWheres());
        return $this->connection->query($sql, array_merge($params, $this->bindings))->rowCount();
    }

    public function delete(): int
    {
        $this->requireTable();

        if (empty($this->wheres)) {
            throw new \LogicException(
                'Refusing mass delete on "' . $this->table . '" without a WHERE clause. '
                . 'Add ->where() to scope the delete, or use ->where(\'1\', \'=\', \'1\') for an intentional bulk delete.'
            );
        }

        $sql = sprintf('DELETE FROM %s%s', $this->quoteIdentifier($this->table), $this->compileWheres());
        return $this->connection->query($sql, $this->bindings)->rowCount();
    }

    public function toSql(): array
    {
        $this->requireTable();
        $bindings = $this->bindings;
        $sql = $this->distinct ? 'SELECT DISTINCT ' : 'SELECT ';
        $sql .= implode(', ', array_map(fn ($c) => $this->compileSelectColumn($c), $this->columns));
        $sql .= ' FROM ' . $this->quoteIdentifier($this->table);

        foreach ($this->joins as $join) {
            $sql .= sprintf(' %s JOIN %s ON %s %s %s', $join['type'], $this->quoteIdentifier($join['table']), $this->quoteIdentifier($join['first']), $join['operator'], $this->quoteIdentifier($join['second']));
        }

        $sql .= $this->compileWheres();

        if (!empty($this->groupBy)) {
            $sql .= ' GROUP BY ' . implode(', ', array_map(fn ($c) => $this->quoteIdentifier($c), $this->groupBy));
        }
        if (!empty($this->orderBy)) {
            $orders = array_map(fn ($o) => $this->quoteIdentifier($o[0]) . ' ' . $o[1], $this->orderBy);
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ?';
            $bindings[] = $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ?';
            $bindings[] = $this->offset;
        }

        return [$sql, $bindings];
    }

    private function requireTable(): void
    {
        if ($this->table === '') {
            throw new LogicException('Cannot execute query: no table has been set. Call ->table() first.');
        }
    }

    private function validateIdentifier(string $identifier, string $type = 'identifier'): void
    {
        if (strlen($identifier) > self::MAX_IDENTIFIER_LENGTH) {
            throw new InvalidArgumentException("Invalid {$type}: exceeds maximum length");
        }
        if (str_contains($identifier, "\0")) {
            throw new InvalidArgumentException("Invalid {$type}: contains null bytes");
        }
        if ($identifier === '*') {
            return;
        }

        // Allow aggregate functions with safe inner expressions only:
        // COUNT(*), COUNT(column), SUM(table.column), etc.
        if (preg_match('/^(COUNT|SUM|AVG|MIN|MAX|COALESCE|IFNULL|NULLIF)\s*\(\s*(\*|[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?)\s*\)$/i', $identifier)) {
            return;
        }
        // Allow "expression AS alias" where expression is a safe identifier or function call
        if (preg_match('/^(.+?)\s+(?:AS\s+)?([a-zA-Z_][a-zA-Z0-9_]*)$/i', $identifier, $matches)) {
            $expr = trim($matches[1]);
            // The expression part must itself be a safe identifier or aggregate function
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $expr)) {
                return;
            }
            if (preg_match('/^(COUNT|SUM|AVG|MIN|MAX|COALESCE|IFNULL|NULLIF)\s*\(\s*(\*|[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?)\s*\)$/i', $expr)) {
                return;
            }
            throw new InvalidArgumentException("Invalid {$type}: unsafe expression in alias");
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $identifier)) {
            throw new InvalidArgumentException("Invalid {$type} name: '{$identifier}'");
        }
    }

    private function validateOperator(string $operator): void
    {
        if (!in_array(strtoupper(trim($operator)), self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid SQL operator: {$operator}");
        }
    }

    private function compileWheres(): string
    {
        if (empty($this->wheres)) {
            return '';
        }
        $parts = [];
        foreach ($this->wheres as $i => $where) {
            $clause = match ($where['type']) {
                'basic' => $this->quoteIdentifier($where['column']) . ' ' . $where['operator'] . ' ?',
                'in' => $this->quoteIdentifier($where['column']) . ' IN (' . implode(', ', array_fill(0, $where['count'], '?')) . ')',
                'null' => $this->quoteIdentifier($where['column']) . ' IS NULL',
                'notNull' => $this->quoteIdentifier($where['column']) . ' IS NOT NULL',
                'between' => $this->quoteIdentifier($where['column']) . ' BETWEEN ? AND ?',
                default => '',
            };
            $parts[] = ($i === 0) ? $clause : $where['boolean'] . ' ' . $clause;
        }
        return ' WHERE ' . implode(' ', $parts);
    }

    private function compileSelectColumn(string $column): string
    {
        // Wildcard
        if ($column === '*') {
            return '*';
        }
        // Raw expressions: function calls (contain '(') or already-built aliases (contain ' ')
        // e.g. "COUNT(*) as aggregate", "SUM(`price`) as aggregate"
        if (str_contains($column, '(') || str_contains($column, ' ')) {
            return $column;
        }
        // Plain identifier or table.column — quote it
        return $this->quoteIdentifier($column);
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (str_contains($identifier, '.')) {
            return implode('.', array_map(fn ($p) => $this->connection->quoteIdentifier($p), explode('.', $identifier)));
        }
        return $this->connection->quoteIdentifier($identifier);
    }
}
