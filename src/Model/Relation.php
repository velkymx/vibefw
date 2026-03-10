<?php

declare(strict_types=1);

namespace Fw\Model;

/**
 * Base class for model relationships.
 *
 * @template TParent of Model
 * @template TRelated of Model
 */
abstract class Relation
{
    /** @var TParent */
    protected Model $parent;

    /** @var class-string<TRelated> */
    protected string $related;

    /** @var ModelQueryBuilder */
    protected ModelQueryBuilder $query;

    /**
     * @param TParent $parent
     * @param class-string<TRelated> $related
     */
    public function __construct(Model $parent, string $related)
    {
        $this->parent = $parent;
        $this->related = $related;
        $this->query = $related::query();
    }

    /**
     * Get the results of the relationship.
     *
     * @return Collection<TRelated>|TRelated|null
     */
    abstract public function get(): mixed;

    /**
     * Eager load the relation for a collection of models.
     *
     * @param Collection<TParent> $models
     */
    abstract public function eagerLoad(Collection $models, string $name): void;

    /**
     * Add constraints for where clauses.
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): static
    {
        $this->query = $this->query->where($column, $operator, $value);
        return $this;
    }

    /**
     * Add ordering.
     */
    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->query = $this->query->orderBy($column, $direction);
        return $this;
    }

    /**
     * Limit results.
     */
    public function limit(int $count): static
    {
        $this->query = $this->query->limit($count);
        return $this;
    }

    /**
     * Get the underlying query builder.
     */
    public function getQuery(): ModelQueryBuilder
    {
        return $this->query;
    }
}
