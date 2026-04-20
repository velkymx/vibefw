<?php

declare(strict_types=1);

namespace Fw\Model;

use Fw\Database\Connection;
use Fw\Database\QueryBuilder;
use Fw\Support\Option;

/**
 * Query builder for Model classes.
 *
 * Extends QueryBuilder with model-aware features like:
 * - Hydration to model instances
 * - Relationship eager loading
 * - Scopes
 */
final class ModelQueryBuilder
{
    private QueryBuilder $query;

    /** @var class-string<Model> */
    private string $modelClass;

    private ModelMetadata $metadata;

    /** @var array<string> Relations to eager load */
    private array $eagerLoad = [];

    /** @var array<string> Scopes to apply */
    private array $scopes = [];

    /**
     * @param class-string<Model> $modelClass
     */
    public function __construct(
        Connection $connection,
        string $modelClass,
        ModelMetadata $metadata
    ) {
        $this->query = new QueryBuilder($connection)->table($metadata->table);
        $this->modelClass = $modelClass;
        $this->metadata = $metadata;
    }

    // ========================================
    // CLONING
    // ========================================

    public function __clone()
    {
        $this->query = clone $this->query;
    }

    // ========================================
    // QUERY METHODS (delegated to QueryBuilder)
    // ========================================

    public function select(string|array $columns = ['*']): self
    {
        $clone = clone $this;
        $clone->query = $this->query->select($columns);
        return $clone;
    }

    public function distinct(): self
    {
        $clone = clone $this;
        $clone->query = $this->query->distinct();
        return $clone;
    }

    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        $clone = clone $this;
        $clone->query = $this->query->where($column, $operator, $value);
        return $clone;
    }

    public function orWhere(string $column, mixed $operator = null, mixed $value = null): self
    {
        $clone = clone $this;
        $clone->query = $this->query->orWhere($column, $operator, $value);
        return $clone;
    }

    public function whereIn(string $column, array $values): self
    {
        $clone = clone $this;
        $clone->query = $this->query->whereIn($column, $values);
        return $clone;
    }

    public function whereNull(string $column): self
    {
        $clone = clone $this;
        $clone->query = $this->query->whereNull($column);
        return $clone;
    }

    public function whereNotNull(string $column): self
    {
        $clone = clone $this;
        $clone->query = $this->query->whereNotNull($column);
        return $clone;
    }

    public function whereBetween(string $column, mixed $min, mixed $max): self
    {
        $clone = clone $this;
        $clone->query = $this->query->whereBetween($column, $min, $max);
        return $clone;
    }

    public function join(string $table, string $first, string $operator, string $second): self
    {
        $clone = clone $this;
        $clone->query = $this->query->join($table, $first, $operator, $second);
        return $clone;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $clone = clone $this;
        $clone->query = $this->query->leftJoin($table, $first, $operator, $second);
        return $clone;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $clone = clone $this;
        $clone->query = $this->query->orderBy($column, $direction);
        return $clone;
    }

    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'DESC');
    }

    public function oldest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'ASC');
    }

    public function groupBy(string|array $columns): self
    {
        $clone = clone $this;
        $clone->query = $this->query->groupBy($columns);
        return $clone;
    }

    public function limit(int $limit): self
    {
        $clone = clone $this;
        $clone->query = $this->query->limit($limit);
        return $clone;
    }

    public function offset(int $offset): self
    {
        $clone = clone $this;
        $clone->query = $this->query->offset($offset);
        return $clone;
    }

    public function take(int $limit): self
    {
        return $this->limit($limit);
    }

    public function skip(int $offset): self
    {
        return $this->offset($offset);
    }

    // ========================================
    // EAGER LOADING
    // ========================================

    /**
     * Eager load relationships.
     *
     * @param string|array<string> $relations
     */
    public function with(string|array $relations): self
    {
        $clone = clone $this;
        $clone->eagerLoad = array_merge(
            $clone->eagerLoad,
            is_array($relations) ? $relations : [$relations]
        );
        return $clone;
    }

    // ========================================
    // RESULT METHODS
    // ========================================

    /**
     * Execute query and get results as Collection of models.
     *
     * @return Collection<Model>
     */
    public function get(): Collection
    {
        $rows = $this->query->get();
        $models = ($this->modelClass)::hydrateMany($rows);

        // Eager load relations
        if (!empty($this->eagerLoad)) {
            $this->loadRelations($models);
        }

        return $models;
    }

    /**
     * Get the first result.
     *
     * @return Option<Model>
     */
    public function first(): Option
    {
        $rowOpt = $this->query->first();

        if ($rowOpt->isNone()) {
            return Option::none();
        }

        $row = $rowOpt->unwrapOrElse(fn () => throw new \LogicException('unreachable'));
        $model = ($this->modelClass)::hydrate($row);

        // Eager load relations
        if (!empty($this->eagerLoad)) {
            $this->loadRelations(new Collection([$model]));
        }

        return Option::some($model);
    }

    /**
     * Get the first result or throw.
     *
     * @throws ModelNotFoundException
     */
    public function firstOrFail(): Model
    {
        return $this->first()->unwrapOrElse(
            fn () => throw ModelNotFoundException::forModel($this->modelClass)
        );
    }

    /**
     * Find a model by primary key.
     *
     * @return Option<Model>
     */
    public function find(mixed $id): Option
    {
        if ($id === '' || $id === null) {
            return Option::none();
        }

        return $this->where($this->metadata->primaryKey, $id)->first();
    }

    /**
     * Find a model by primary key or throw.
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(mixed $id): Model
    {
        return $this->find($id)->unwrapOrElse(
            fn () => throw ModelNotFoundException::forModel($this->modelClass, $id)
        );
    }

    /**
     * Pluck a single column's value.
     *
     * @return array<mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $rows = $this->query->select($key ? [$column, $key] : [$column])->get();

        if ($key === null) {
            return array_column($rows, $column);
        }

        $result = [];
        foreach ($rows as $row) {
            $result[$row[$key]] = $row[$column];
        }

        return $result;
    }

    /**
     * Get a single column's value from the first result.
     */
    public function value(string $column): mixed
    {
        return $this->query->select([$column])->first()->unwrapOr([])[$column] ?? null;
    }

    // ========================================
    // AGGREGATE METHODS
    // ========================================

    public function count(): int
    {
        return $this->query->count();
    }

    public function exists(): bool
    {
        return $this->query->exists();
    }

    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    public function sum(string $column): float
    {
        return $this->query->sum($column);
    }

    public function avg(string $column): float
    {
        return $this->query->avg($column);
    }

    public function min(string $column): mixed
    {
        return $this->query->min($column);
    }

    public function max(string $column): mixed
    {
        return $this->query->max($column);
    }

    // ========================================
    // MUTATION METHODS
    // ========================================

    /**
     * Insert a new record.
     *
     * @param array<string, mixed> $values
     */
    public function insert(array $values): int
    {
        return $this->query->insert($values);
    }

    /**
     * Update records matching the query.
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values): int
    {
        return $this->query->update($values);
    }

    /**
     * Delete records matching the query.
     */
    public function delete(): int
    {
        return $this->query->delete();
    }

    // ========================================
    // PAGINATION
    // ========================================

    /**
     * Paginate the results.
     *
     * @return array{items: Collection, total: int, per_page: int, current_page: int, last_page: int}
     */
    public function paginate(int $perPage = 15, int $page = 1, bool $useWindow = false): array
    {
        $result = $this->query->paginate($perPage, $page, $useWindow);

        return [
            'items' => ($this->modelClass)::hydrateMany($result['items']),
            'total' => $result['total'],
            'per_page' => $result['per_page'],
            'current_page' => $result['current_page'],
            'last_page' => $result['last_page'],
        ];
    }

    /**
     * Chunk results for memory-efficient processing.
     *
     * @param callable(Collection): bool|void $callback Return false to stop
     */
    public function chunk(int $count, callable $callback): void
    {
        $page = 1;

        do {
            $results = $this->limit($count)->offset(($page - 1) * $count)->get();

            if ($results->isEmpty()) {
                break;
            }

            if ($callback($results) === false) {
                break;
            }

            $page++;
        } while ($results->count() === $count);
    }

    /**
     * Get the underlying query builder.
     */
    public function getQuery(): QueryBuilder
    {
        return $this->query;
    }

    /**
     * Get the SQL and bindings.
     *
     * @return array{0: string, 1: array<mixed>}
     */
    public function toSql(): array
    {
        return $this->query->toSql();
    }

    // ========================================
    // EAGER LOADING IMPLEMENTATION
    // ========================================

    /**
     * Load eager-loaded relations onto models.
     *
     * @param Collection<Model> $models
     */
    private function loadRelations(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        foreach ($this->eagerLoad as $relation) {
            $this->loadRelation($models, $relation);
        }
    }

    /**
     * Load a single relation onto models.
     *
     * @param Collection<Model> $models
     */
    private function loadRelation(Collection $models, string $name): void
    {
        // Get the first model to access the relationship definition
        $first = $models->first()->unwrapOr(null);
        if ($first === null) {
            return;
        }

        if (!method_exists($first, $name)) {
            return;
        }

        $relation = $first->$name();

        if (!$relation instanceof Relation) {
            return;
        }

        // Let the relation handle eager loading
        $relation->eagerLoad($models, $name);
    }
}
