<?php

declare(strict_types=1);

namespace Fw\Repository;

use Fw\Async\AsyncDatabase;
use Fw\Async\Deferred;
use Fw\Database\Connection;
use Fw\Domain\Id;
use Fw\Support\Option;
use Fw\Support\Str;
use ReflectionObject;
use ReflectionProperty;
use RuntimeException;

/**
 * Async-aware database repository base class.
 *
 * Provides a simple Active Record-style interface that works with
 * the Fiber-based async system. All database operations return
 * Deferred values that can be awaited.
 *
 * @template T The entity type
 * @template TId of Id The entity ID type
 *
 * @example
 *     class UserRepository extends AsyncRepository
 *     {
 *         protected string $table = 'users';
 *         protected string $entityClass = User::class;
 *         protected string $idClass = UserId::class;
 *
 *         public function findByEmail(Email $email): Option
 *         {
 *             return $this->findOneBy(['email' => $email->value]);
 *         }
 *     }
 *
 *     // In a controller or component (inside a Fiber)
 *     $user = $userRepository->find($userId)->await();
 */
abstract class AsyncRepository implements Repository
{
    protected AsyncDatabase $db;

    /** Table name */
    protected string $table;

    /** Entity class name */
    protected string $entityClass;

    /** ID class name */
    protected string $idClass;

    /** Primary key column */
    protected string $primaryKey = 'id';

    /** Timestamps columns (null to disable) */
    protected ?string $createdAt = 'created_at';

    protected ?string $updatedAt = 'updated_at';

    public function __construct(Connection $connection)
    {
        $this->db = new AsyncDatabase($connection);
    }

    /**
     * Find an entity by ID.
     *
     * @param TId $id
     * @return Option<T>
     */
    public function find(Id $id): Option
    {
        $row = $this->db
            ->fetchOne(
                "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ?",
                [$id->value]
            )
            ->await();

        return Option::fromNullable($row)->map(fn ($data) => $this->hydrate($data));
    }

    /**
     * Find an entity by ID, throwing if not found.
     *
     * @param TId $id
     * @return T
     * @throws EntityNotFoundException
     */
    public function findOrFail(Id $id): mixed
    {
        return $this->find($id)->unwrapOrElse(
            fn () => throw EntityNotFoundException::for($this->entityClass, $id)
        );
    }

    /**
     * Check if an entity exists.
     *
     * @param TId $id
     */
    public function exists(Id $id): bool
    {
        $result = $this->db
            ->fetchOne(
                "SELECT 1 FROM {$this->table} WHERE {$this->primaryKey} = ?",
                [$id->value]
            )
            ->await();

        return $result !== null;
    }

    /**
     * Save an entity (insert or update).
     *
     * @param T $entity
     */
    public function save(mixed $entity): void
    {
        $id = $this->getId($entity);
        $data = $this->dehydrate($entity);

        if ($this->exists($id)) {
            $this->update($id, $data);
        } else {
            $this->insert($data);
        }
    }

    /**
     * Delete an entity.
     *
     * @param T $entity
     */
    public function delete(mixed $entity): void
    {
        $this->deleteById($this->getId($entity));
    }

    /**
     * Delete an entity by ID.
     *
     * @param TId $id
     */
    public function deleteById(Id $id): void
    {
        $this->db
            ->query(
                "DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?",
                [$id->value]
            )
            ->await();
    }

    /**
     * Find all entities.
     *
     * @return array<T>
     */
    public function all(): array
    {
        $rows = $this->db
            ->fetchAll("SELECT * FROM {$this->table}")
            ->await();

        return array_map(fn ($row) => $this->hydrate($row), $rows);
    }

    /**
     * Find entities by criteria.
     *
     * @return array<T>
     */
    public function findByCriteria(Criteria $criteria): array
    {
        [$sql, $params] = $this->buildQuery($criteria);

        $rows = $this->db->fetchAll($sql, $params)->await();

        return array_map(fn ($row) => $this->hydrate($row), $rows);
    }

    /**
     * Find first entity matching criteria.
     *
     * @return Option<T>
     */
    public function findOneByCriteria(Criteria $criteria): Option
    {
        $criteria = $criteria->limit(1);
        $results = $this->findByCriteria($criteria);

        return Option::fromNullable($results[0] ?? null);
    }

    /**
     * Count entities matching criteria.
     */
    public function countByCriteria(Criteria $criteria): int
    {
        [$whereSql, $params] = $this->buildWhere($criteria);

        $sql = "SELECT COUNT(*) as count FROM {$this->table}" . $whereSql;

        $result = $this->db->fetchOne($sql, $params)->await();

        return (int) ($result['count'] ?? 0);
    }

    /**
     * Find one entity by column values.
     *
     * @param array<string, mixed> $conditions
     * @return Option<T>
     */
    public function findOneBy(array $conditions): Option
    {
        $criteria = Criteria::create();

        foreach ($conditions as $field => $value) {
            $criteria = $criteria->whereEquals($field, $value);
        }

        return $this->findOneByCriteria($criteria);
    }

    /**
     * Find entities by column values.
     *
     * @param array<string, mixed> $conditions
     * @return array<T>
     */
    public function findBy(array $conditions): array
    {
        $criteria = Criteria::create();

        foreach ($conditions as $field => $value) {
            $criteria = $criteria->whereEquals($field, $value);
        }

        return $this->findByCriteria($criteria);
    }

    /**
     * Get count of all entities.
     */
    public function count(): int
    {
        $result = $this->db
            ->fetchOne("SELECT COUNT(*) as count FROM {$this->table}")
            ->await();

        return (int) ($result['count'] ?? 0);
    }

    /**
     * Find entities async (returns Deferred).
     *
     * @return Deferred<array<T>>
     */
    public function findAsync(Id $id): Deferred
    {
        $deferred = new Deferred();

        $this->db
            ->fetchOne(
                "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ?",
                [$id->value]
            )
            ->await();

        // This is handled synchronously in the current implementation
        // but the interface supports true async when drivers support it

        return Deferred::resolved($this->find($id));
    }

    // ========================================
    // PROTECTED METHODS FOR SUBCLASSES
    // ========================================

    /**
     * Insert a new record.
     *
     * @param array<string, mixed> $data
     */
    protected function insert(array $data): void
    {
        if ($this->createdAt && !isset($data[$this->createdAt])) {
            $data[$this->createdAt] = date('Y-m-d H:i:s');
        }

        if ($this->updatedAt && !isset($data[$this->updatedAt])) {
            $data[$this->updatedAt] = date('Y-m-d H:i:s');
        }

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $this->db
            ->query(
                "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})",
                array_values($data)
            )
            ->await();
    }

    /**
     * Update an existing record.
     *
     * @param TId $id
     * @param array<string, mixed> $data
     */
    protected function update(Id $id, array $data): void
    {
        if ($this->updatedAt) {
            $data[$this->updatedAt] = date('Y-m-d H:i:s');
        }

        // Remove primary key from data
        unset($data[$this->primaryKey]);

        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = "{$column} = ?";
        }

        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s = ?",
            $this->table,
            implode(', ', $sets),
            $this->primaryKey
        );

        $params = array_values($data);
        $params[] = $id->value;

        $this->db->query($sql, $params)->await();
    }

    /**
     * Get ID from entity.
     *
     * @param T $entity
     * @return TId
     */
    protected function getId(mixed $entity): Id
    {
        if (property_exists($entity, 'id')) {
            return $entity->id;
        }

        throw new RuntimeException('Entity must have an id property');
    }

    /**
     * Convert database row to entity.
     *
     * Override for custom hydration.
     *
     * @param array<string, mixed> $data
     * @return T
     */
    protected function hydrate(array $data): mixed
    {
        $class = $this->entityClass;

        if (method_exists($class, 'fromArray')) {
            return $class::fromArray($data);
        }

        // Default: create with ID and map other properties
        $idClass = $this->idClass;
        $id = $idClass::fromTrusted($data[$this->primaryKey]);

        unset($data[$this->primaryKey]);

        return new $class($id, ...$data);
    }

    /**
     * Convert entity to database row.
     *
     * Override for custom dehydration.
     *
     * @param T $entity
     * @return array<string, mixed>
     */
    protected function dehydrate(mixed $entity): array
    {
        if (method_exists($entity, 'toArray')) {
            return $entity->toArray();
        }

        // Default: get public properties
        $data = [];
        $reflection = new ReflectionObject($entity);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($entity);

            // Convert Value Objects to their primitive values
            if ($value instanceof Id) {
                $value = $value->value;
            } elseif (is_object($value) && method_exists($value, '__toString')) {
                $value = (string) $value;
            }

            $data[Str::snake($prop->getName())] = $value;
        }

        return $data;
    }

    /**
     * Build SQL query from Criteria.
     *
     * @return array{0: string, 1: array<mixed>}
     */
    protected function buildQuery(Criteria $criteria): array
    {
        [$whereSql, $params] = $this->buildWhere($criteria);

        $sql = "SELECT * FROM {$this->table}" . $whereSql;

        // Order by
        if ($criteria->hasOrdering()) {
            $orders = [];
            foreach ($criteria->getOrderBy() as $order) {
                $orders[] = "{$order['field']} {$order['direction']}";
            }
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }

        // Limit & offset
        if ($criteria->getLimit() !== null) {
            $sql .= ' LIMIT ' . $criteria->getLimit();
        }

        if ($criteria->getOffset() !== null) {
            $sql .= ' OFFSET ' . $criteria->getOffset();
        }

        return [$sql, $params];
    }

    /**
     * Build WHERE clause from Criteria.
     *
     * @return array{0: string, 1: array<mixed>}
     */
    protected function buildWhere(Criteria $criteria): array
    {
        if (!$criteria->hasConditions()) {
            return ['', []];
        }

        $clauses = [];
        $params = [];

        foreach ($criteria->getConditions() as $condition) {
            $field = $condition['field'];
            $operator = $condition['operator'];
            $value = $condition['value'];

            if ($operator === 'IN' || $operator === 'NOT IN') {
                $placeholders = implode(', ', array_fill(0, count((array) $value), '?'));
                $clauses[] = "{$field} {$operator} ({$placeholders})";
                $params = array_merge($params, (array) $value);
            } elseif ($operator === 'IS' || $operator === 'IS NOT') {
                $clauses[] = "{$field} {$operator} NULL";
            } elseif ($operator === 'LIKE') {
                $clauses[] = "{$field} LIKE ?";
                $params[] = $value;
            } else {
                $clauses[] = "{$field} {$operator} ?";
                $params[] = $value;
            }
        }

        return [' WHERE ' . implode(' AND ', $clauses), $params];
    }
}
