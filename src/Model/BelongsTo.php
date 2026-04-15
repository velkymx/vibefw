<?php

declare(strict_types=1);

namespace Fw\Model;

use Fw\Support\Option;

/**
 * Belongs-to relationship.
 *
 * @template TParent of Model
 * @template TRelated of Model
 * @extends Relation<TParent, TRelated>
 */
final class BelongsTo extends Relation
{
    /**
     * @param TParent $parent
     * @param class-string<TRelated> $related
     */
    public function __construct(
        Model $parent,
        string $related,
        private readonly string $foreignKey,
        private readonly string $ownerKey,
    ) {
        parent::__construct($parent, $related);
    }

    /**
     * Get the related model.
     *
     * @return Option<TRelated>
     */
    public function get(): Option
    {
        $foreignValue = $this->parent->getAttribute($this->foreignKey)->unwrapOr(null);

        if ($foreignValue === null) {
            return Option::none();
        }

        return $this->query
            ->where($this->ownerKey, $foreignValue)
            ->first();
    }

    /**
     * Eager load the relation for a collection of models.
     *
     * @param Collection<TParent> $models
     */
    public function eagerLoad(Collection $models, string $name): void
    {
        // Get all foreign keys
        $keys = $models->pluck($this->foreignKey);
        $keys = array_filter(array_unique($keys));

        if (empty($keys)) {
            // Set null for all models
            foreach ($models as $model) {
                $model->setRelation($name, null);
            }
            return;
        }

        // Fetch all related models
        $related = ($this->related)::whereIn($this->ownerKey, $keys)->get();

        // Key by owner key for fast lookup
        $dictionary = [];
        foreach ($related as $item) {
            $ownerValue = $item->getAttribute($this->ownerKey)->unwrapOr(null);
            $dictionary[$ownerValue] = $item;
        }

        // Assign to parent models
        foreach ($models as $model) {
            $key = $model->getAttribute($this->foreignKey)->unwrapOr(null);
            $model->setRelation($name, $dictionary[$key] ?? null);
        }
    }

    /**
     * Associate a related model.
     *
     * @param TRelated|mixed $model Model instance or key value
     */
    public function associate(mixed $model): Model
    {
        $key = $model instanceof Model ? $model->getAttribute($this->ownerKey)->unwrapOr(null) : $model;
        $this->parent->setAttribute($this->foreignKey, $key);
        return $this->parent;
    }

    /**
     * Dissociate the related model.
     */
    public function dissociate(): Model
    {
        $this->parent->setAttribute($this->foreignKey, null);
        return $this->parent;
    }

    /**
     * Get the foreign key name.
     */
    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    /**
     * Get the owner key name.
     */
    public function getOwnerKey(): string
    {
        return $this->ownerKey;
    }
}
