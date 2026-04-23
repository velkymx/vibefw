<?php

declare(strict_types=1);

namespace Fw\Repository;

use Fw\Domain\Id;
use Fw\Support\Arr;
use Fw\Support\Option;

/**
 * In-memory repository implementation for testing.
 *
 * Provides a complete Repository implementation backed by an array.
 * Use this for unit testing without database dependencies.
 *
 * Usage:
 *     // In your test
 *     $repository = new InMemoryUserRepository();
 *     $repository->save($user);
 *
 *     // Assertions
 *     $found = $repository->find($user->id);
 *     $this->assertTrue($found->isSome());
 *
 * @template T The entity type
 * @template TId of Id The entity ID type
 * @implements Repository<T, TId>
 */
abstract class InMemoryRepository implements Repository
{
    /** @var array<string, T> */
    protected array $entities = [];

    /**
     * @param TId $id
     * @return Option<T>
     */
    public function find(Id $id): Option
    {
        return Option::fromNullable($this->entities[$id->value] ?? null);
    }

    /**
     * @param TId $id
     * @return T
     * @throws EntityNotFoundException
     */
    public function findOrFail(Id $id): mixed
    {
        return $this->find($id)->unwrapOrElse(
            fn () => throw EntityNotFoundException::for($this->getEntityClass(), $id)
        );
    }

    /**
     * @param TId $id
     */
    public function exists(Id $id): bool
    {
        return isset($this->entities[$id->value]);
    }

    /**
     * @param T $entity
     */
    public function save(mixed $entity): void
    {
        $id = $this->getId($entity);
        $this->entities[$id->value] = $entity;
    }

    /**
     * @param T $entity
     */
    public function delete(mixed $entity): void
    {
        $id = $this->getId($entity);
        unset($this->entities[$id->value]);
    }

    /**
     * @param TId $id
     */
    public function deleteById(Id $id): void
    {
        unset($this->entities[$id->value]);
    }

    /**
     * Get all entities.
     *
     * @return array<T>
     */
    public function all(): array
    {
        return array_values($this->entities);
    }

    /**
     * Count all entities.
     */
    public function count(): int
    {
        return count($this->entities);
    }

    /**
     * Find entities matching criteria.
     *
     * @return array<T>
     */
    public function findByCriteria(Criteria $criteria): array
    {
        $results = $this->entities;

        // Apply conditions
        foreach ($criteria->getConditions() as $condition) {
            $results = array_filter($results, function ($entity) use ($condition) {
                return $this->matchesCondition($entity, $condition);
            });
        }

        // Apply ordering
        foreach (array_reverse($criteria->getOrderBy()) as $order) {
            $results = Arr::sortBy(
                $results,
                fn ($e) => $this->getFieldValue($e, $order['field']),
                SORT_REGULAR,
                $order['direction'] === 'desc'
            );
        }

        // Apply offset and limit
        $offset = $criteria->getOffset() ?? 0;
        $limit = $criteria->getLimit();

        return array_values(
            array_slice($results, $offset, $limit, false)
        );
    }

    /**
     * Find first entity matching criteria.
     *
     * @return Option<T>
     */
    public function findOneByCriteria(Criteria $criteria): Option
    {
        $results = $this->findByCriteria($criteria->limit(1));
        return Option::fromNullable($results[0] ?? null);
    }

    /**
     * Count entities matching criteria.
     */
    public function countByCriteria(Criteria $criteria): int
    {
        // Remove limit/offset for counting
        return count($this->findByCriteria($criteria));
    }

    /**
     * Clear all entities (useful for testing).
     */
    public function clear(): void
    {
        $this->entities = [];
    }

    /**
     * Get the ID from an entity.
     *
     * @param T $entity
     * @return TId
     */
    abstract protected function getId(mixed $entity): Id;

    /**
     * Get the entity class name for error messages.
     */
    abstract protected function getEntityClass(): string;

    /**
     * Check if entity matches a condition.
     *
     * @param T $entity
     * @param array{field: string, operator: string, value: mixed} $condition
     */
    protected function matchesCondition(mixed $entity, array $condition): bool
    {
        $value = $this->getFieldValue($entity, $condition['field']);
        $expected = $condition['value'];

        return match ($condition['operator']) {
            '=' => $value === $expected,
            '!=' => $value !== $expected,
            '>' => $value > $expected,
            '>=' => $value >= $expected,
            '<' => $value < $expected,
            '<=' => $value <= $expected,
            'IN' => in_array($value, (array) $expected, true),
            'NOT IN' => !in_array($value, (array) $expected, true),
            'IS' => $value === $expected,
            'IS NOT' => $value !== $expected,
            'LIKE' => $this->matchesLike($value, $expected),
            default => false,
        };
    }

    /**
     * Get a field value from an entity.
     *
     * @param T $entity
     */
    protected function getFieldValue(mixed $entity, string $field): mixed
    {
        // Support dot notation for nested fields
        if (str_contains($field, '.')) {
            return Arr::get((array) $entity, $field);
        }

        // Try property access
        if (is_object($entity) && property_exists($entity, $field)) {
            return $entity->{$field};
        }

        // Try array access
        if (is_array($entity) && isset($entity[$field])) {
            return $entity[$field];
        }

        // Try getter method
        if (is_object($entity)) {
            $getter = 'get' . ucfirst($field);
            if (method_exists($entity, $getter)) {
                return $entity->{$getter}();
            }
        }

        return null;
    }

    /**
     * Match an SQL-style LIKE pattern.
     *
     * Walks the pattern byte-by-byte so `%`, `_`, and their `\`-escaped
     * forms are handled directly instead of through a str_replace
     * double-dance (which couldn't work: `preg_quote` never touched
     * `%`/`_` so the second pass had nothing to undo, and any
     * backslash in the pattern corrupted the escapes it actually
     * cared about).
     *
     * Rules:
     *  - `%` → `.*`
     *  - `_` → `.`
     *  - `\%`, `\_`, `\\` → literal `%`, `_`, `\` respectively
     *  - Every other byte is literal (dots, brackets, pipes, parens,
     *    `+`, `*`, etc. are NOT regex metacharacters in LIKE).
     *  - The match is case-insensitive (historical behaviour preserved).
     */
    protected function matchesLike(mixed $value, string $pattern): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $regex = '';
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $c = $pattern[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $next = $pattern[$i + 1];
                if ($next === '%' || $next === '_' || $next === '\\') {
                    $regex .= preg_quote($next, '/');
                    $i++;
                    continue;
                }
                // Unknown escape: keep the backslash literal and let
                // the next byte be processed normally.
                $regex .= preg_quote('\\', '/');
                continue;
            }
            if ($c === '%') {
                $regex .= '.*';
                continue;
            }
            if ($c === '_') {
                $regex .= '.';
                continue;
            }
            $regex .= preg_quote($c, '/');
        }

        return preg_match('/^' . $regex . '$/i', $value) === 1;
    }
}
