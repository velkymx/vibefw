<?php

declare(strict_types=1);

namespace Fw\Model;

/**
 * Belongs-to-many (many-to-many) relationship.
 *
 * @template TParent of Model
 * @template TRelated of Model
 * @extends Relation<TParent, TRelated>
 */
final class BelongsToMany extends Relation
{
    /**
     * @param TParent $parent
     * @param class-string<TRelated> $related
     */
    public function __construct(
        Model $parent,
        string $related,
        private readonly string $table,
        private readonly string $foreignPivotKey,
        private readonly string $relatedPivotKey,
    ) {
        parent::__construct($parent, $related);
    }

    /**
     * Get the related models.
     *
     * @return Collection<TRelated>
     */
    public function get(): Collection
    {
        $parentKey = $this->parent->getKey();

        if ($parentKey === null) {
            return Collection::empty();
        }

        $relatedTable = ($this->related)::getTable();
        $relatedKey = ($this->related)::getKeyName();

        // Join through pivot table
        return $this->query
            ->join(
                $this->table,
                "{$relatedTable}.{$relatedKey}",
                '=',
                "{$this->table}.{$this->relatedPivotKey}"
            )
            ->where("{$this->table}.{$this->foreignPivotKey}", $parentKey)
            ->select("{$relatedTable}.*")
            ->get();
    }

    /**
     * Eager load the relation for a collection of models.
     *
     * @param Collection<TParent> $models
     */
    public function eagerLoad(Collection $models, string $name): void
    {
        $parentTable = $this->parent::getTable();
        $parentKey = $this->parent::getKeyName();

        // Get all parent keys
        $keys = $models->pluck($parentKey);
        $keys = array_filter(array_unique($keys));

        if (empty($keys)) {
            foreach ($models as $model) {
                $model->setRelation($name, Collection::empty());
            }
            return;
        }

        $relatedTable = ($this->related)::getTable();
        $relatedKey = ($this->related)::getKeyName();

        // Fetch all related models with pivot info
        $connection = Model::getConnection();
        $rows = $connection->select(
            "SELECT {$relatedTable}.*, {$this->table}.{$this->foreignPivotKey} as pivot_foreign_key
             FROM {$relatedTable}
             INNER JOIN {$this->table} ON {$relatedTable}.{$relatedKey} = {$this->table}.{$this->relatedPivotKey}
             WHERE {$this->table}.{$this->foreignPivotKey} IN (" . implode(',', array_fill(0, count($keys), '?')) . ")",
            $keys
        );

        // Group by pivot foreign key
        $dictionary = [];
        foreach ($rows as $row) {
            $pivotKey = $row['pivot_foreign_key'];
            unset($row['pivot_foreign_key']);
            $dictionary[$pivotKey][] = ($this->related)::hydrate($row);
        }

        // Assign to parent models
        foreach ($models as $model) {
            $key = $model->getAttribute($parentKey);
            $model->setRelation($name, new Collection($dictionary[$key] ?? []));
        }
    }

    /**
     * Attach related models.
     *
     * @param mixed $ids Single ID, array of IDs, or array of [id => attributes]
     * @param array<string, mixed> $attributes Extra pivot attributes
     */
    public function attach(mixed $ids, array $attributes = []): void
    {
        $parentKey = $this->parent->getKey();

        if ($parentKey === null) {
            throw new \RuntimeException('Cannot attach to unsaved model');
        }

        $connection = Model::getConnection();

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        foreach ($ids as $id => $value) {
            // Handle [id => attributes] format
            if (is_array($value)) {
                $relatedId = $id;
                $pivotAttributes = array_merge($attributes, $value);
            } else {
                $relatedId = $value;
                $pivotAttributes = $attributes;
            }

            $data = array_merge([
                $this->foreignPivotKey => $parentKey,
                $this->relatedPivotKey => $relatedId,
            ], $pivotAttributes);

            $connection->insert($this->table, $data);
        }
    }

    /**
     * Detach related models.
     *
     * @param mixed $ids Single ID, array of IDs, or null for all
     */
    public function detach(mixed $ids = null): int
    {
        $parentKey = $this->parent->getKey();

        if ($parentKey === null) {
            return 0;
        }

        $connection = Model::getConnection();

        $where = [$this->foreignPivotKey => $parentKey];

        if ($ids !== null) {
            if (!is_array($ids)) {
                $ids = [$ids];
            }

            // Build query with IN clause
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            return $connection->query(
                "DELETE FROM {$this->table} WHERE {$this->foreignPivotKey} = ? AND {$this->relatedPivotKey} IN ({$placeholders})",
                array_merge([$parentKey], $ids)
            )->rowCount();
        }

        return $connection->delete($this->table, $where);
    }

    /**
     * Sync related models (detach all and attach new).
     *
     * @param array<mixed> $ids
     * @return array{attached: array, detached: array}
     */
    public function sync(array $ids): array
    {
        $current = $this->pluck();

        // Calculate what to detach and attach
        $toDetach = array_diff($current, $ids);
        $toAttach = array_diff($ids, $current);

        if (!empty($toDetach)) {
            $this->detach($toDetach);
        }

        if (!empty($toAttach)) {
            $this->attach($toAttach);
        }

        return [
            'attached' => $toAttach,
            'detached' => $toDetach,
        ];
    }

    /**
     * Toggle related models (attach if not present, detach if present).
     *
     * @param array<mixed> $ids
     * @return array{attached: array, detached: array}
     */
    public function toggle(array $ids): array
    {
        $current = $this->pluck();

        $toAttach = array_diff($ids, $current);
        $toDetach = array_intersect($ids, $current);

        if (!empty($toDetach)) {
            $this->detach($toDetach);
        }

        if (!empty($toAttach)) {
            $this->attach($toAttach);
        }

        return [
            'attached' => $toAttach,
            'detached' => $toDetach,
        ];
    }

    /**
     * Get IDs of related models.
     *
     * @return array<mixed>
     */
    public function pluck(): array
    {
        $parentKey = $this->parent->getKey();

        if ($parentKey === null) {
            return [];
        }

        $connection = Model::getConnection();
        $rows = $connection->select(
            "SELECT {$this->relatedPivotKey} FROM {$this->table} WHERE {$this->foreignPivotKey} = ?",
            [$parentKey]
        );

        return array_column($rows, $this->relatedPivotKey);
    }

    /**
     * Check if related model is attached.
     */
    public function contains(mixed $id): bool
    {
        return in_array($id, $this->pluck(), false);
    }

    /**
     * Get the pivot table name.
     */
    public function getTable(): string
    {
        return $this->table;
    }
}
