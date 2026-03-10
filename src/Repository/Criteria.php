<?php

declare(strict_types=1);

namespace Fw\Repository;

/**
 * Criteria for querying repositories.
 *
 * Provides a fluent interface for building queries without
 * coupling to specific database syntax.
 *
 * Usage:
 *     $criteria = Criteria::create()
 *         ->where('status', '=', 'active')
 *         ->where('created_at', '>=', $since)
 *         ->orderBy('name', 'asc')
 *         ->limit(10);
 *
 *     $users = $userRepository->findByCriteria($criteria);
 */
final class Criteria
{
    /** @var array<array{field: string, operator: string, value: mixed}> */
    private array $conditions = [];

    /** @var array<array{field: string, direction: string}> */
    private array $orderBy = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /** @var array<string> */
    private array $includes = [];

    private function __construct() {}

    /**
     * Create a new Criteria instance.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Add a where condition.
     */
    public function where(string $field, string $operator, mixed $value): self
    {
        $clone = clone $this;
        $clone->conditions[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
        ];

        return $clone;
    }

    /**
     * Add an equality condition.
     */
    public function whereEquals(string $field, mixed $value): self
    {
        return $this->where($field, '=', $value);
    }

    /**
     * Add a "where in" condition.
     */
    public function whereIn(string $field, array $values): self
    {
        return $this->where($field, 'IN', $values);
    }

    /**
     * Add a "where not in" condition.
     */
    public function whereNotIn(string $field, array $values): self
    {
        return $this->where($field, 'NOT IN', $values);
    }

    /**
     * Add a "where null" condition.
     */
    public function whereNull(string $field): self
    {
        return $this->where($field, 'IS', null);
    }

    /**
     * Add a "where not null" condition.
     */
    public function whereNotNull(string $field): self
    {
        return $this->where($field, 'IS NOT', null);
    }

    /**
     * Add a "where like" condition.
     */
    public function whereLike(string $field, string $pattern): self
    {
        return $this->where($field, 'LIKE', $pattern);
    }

    /**
     * Add an order by clause.
     */
    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $clone = clone $this;
        $clone->orderBy[] = [
            'field' => $field,
            'direction' => strtolower($direction) === 'desc' ? 'desc' : 'asc',
        ];

        return $clone;
    }

    /**
     * Add descending order by.
     */
    public function orderByDesc(string $field): self
    {
        return $this->orderBy($field, 'desc');
    }

    /**
     * Set the limit.
     */
    public function limit(int $limit): self
    {
        $clone = clone $this;
        $clone->limit = $limit;

        return $clone;
    }

    /**
     * Set the offset.
     */
    public function offset(int $offset): self
    {
        $clone = clone $this;
        $clone->offset = $offset;

        return $clone;
    }

    /**
     * Include related entities (eager loading hint).
     */
    public function include(string ...$relations): self
    {
        $clone = clone $this;
        $clone->includes = array_merge($clone->includes, $relations);

        return $clone;
    }

    /**
     * Get all conditions.
     *
     * @return array<array{field: string, operator: string, value: mixed}>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /**
     * Get order by clauses.
     *
     * @return array<array{field: string, direction: string}>
     */
    public function getOrderBy(): array
    {
        return $this->orderBy;
    }

    /**
     * Get the limit.
     */
    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * Get the offset.
     */
    public function getOffset(): ?int
    {
        return $this->offset;
    }

    /**
     * Get included relations.
     *
     * @return array<string>
     */
    public function getIncludes(): array
    {
        return $this->includes;
    }

    /**
     * Check if criteria has any conditions.
     */
    public function hasConditions(): bool
    {
        return !empty($this->conditions);
    }

    /**
     * Check if criteria has ordering.
     */
    public function hasOrdering(): bool
    {
        return !empty($this->orderBy);
    }
}
