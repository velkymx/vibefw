<?php

declare(strict_types=1);

namespace Fw\Model;

/**
 * Has-many relationship.
 *
 * @template TParent of Model
 * @template TRelated of Model
 * @extends Relation<TParent, TRelated>
 */
final class HasMany extends Relation
{
    /**
     * @param TParent $parent
     * @param class-string<TRelated> $related
     */
    public function __construct(
        Model $parent,
        string $related,
        private readonly string $foreignKey,
        private readonly string $localKey,
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
        $localValue = $this->parent->getAttribute($this->localKey);

        if ($localValue === null) {
            return Collection::empty();
        }

        return $this->query
            ->where($this->foreignKey, $localValue)
            ->get();
    }

    /**
     * Eager load the relation for a collection of models.
     *
     * @param Collection<TParent> $models
     */
    public function eagerLoad(Collection $models, string $name): void
    {
        // Get all parent keys
        $keys = $models->pluck($this->localKey);
        $keys = array_filter(array_unique($keys));

        if (empty($keys)) {
            // Set empty collection for all models
            foreach ($models as $model) {
                $model->setRelation($name, Collection::empty());
            }
            return;
        }

        // Fetch all related models
        $related = ($this->related)::whereIn($this->foreignKey, $keys)->get();

        // Group by foreign key
        $dictionary = [];
        foreach ($related as $item) {
            $foreignValue = $item->getAttribute($this->foreignKey);
            $dictionary[$foreignValue][] = $item;
        }

        // Assign to parent models
        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey);
            $model->setRelation($name, new Collection($dictionary[$key] ?? []));
        }
    }

    /**
     * Create a new related model.
     *
     * @param array<string, mixed> $attributes
     * @return TRelated
     */
    public function create(array $attributes): Model
    {
        $attributes[$this->foreignKey] = $this->parent->getAttribute($this->localKey);
        return ($this->related)::create($attributes);
    }

    /**
     * Create multiple related models.
     *
     * @param array<array<string, mixed>> $records
     * @return Collection<TRelated>
     */
    public function createMany(array $records): Collection
    {
        $models = [];

        foreach ($records as $attributes) {
            $models[] = $this->create($attributes);
        }

        return new Collection($models);
    }

    /**
     * Save a related model.
     *
     * @param TRelated $model
     */
    public function save(Model $model): Model
    {
        $model->setAttribute($this->foreignKey, $this->parent->getAttribute($this->localKey));
        $model->save()->match(ok: fn () => null, err: fn ($e) => throw $e);
        return $model;
    }

    /**
     * Save multiple related models.
     *
     * @param iterable<TRelated> $models
     * @return Collection<TRelated>
     */
    public function saveMany(iterable $models): Collection
    {
        $saved = [];

        foreach ($models as $model) {
            $saved[] = $this->save($model);
        }

        return new Collection($saved);
    }

    /**
     * Get count of related models.
     */
    public function count(): int
    {
        $localValue = $this->parent->getAttribute($this->localKey);

        if ($localValue === null) {
            return 0;
        }

        return $this->query
            ->where($this->foreignKey, $localValue)
            ->count();
    }
}
