<?php

declare(strict_types=1);

namespace Fw\Model;

use Fw\Support\Option;

/**
 * Has-one relationship.
 *
 * @template TParent of Model
 * @template TRelated of Model
 * @extends Relation<TParent, TRelated>
 */
final class HasOne extends Relation
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
     * Get the related model.
     *
     * @return Option<TRelated>
     */
    public function get(): Option
    {
        $localValue = $this->parent->getAttribute($this->localKey)->unwrapOr(null);

        if ($localValue === null) {
            return Option::none();
        }

        return $this->query
            ->where($this->foreignKey, $localValue)
            ->first();
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
            // Set null for all models
            foreach ($models as $model) {
                $model->setRelation($name, null);
            }
            return;
        }

        // Fetch all related models
        $related = ($this->related)::whereIn($this->foreignKey, $keys)->get();

        // Key by foreign key for fast lookup
        $dictionary = [];
        foreach ($related as $item) {
            $foreignValue = $item->getAttribute($this->foreignKey)->unwrapOr(null);
            $dictionary[$foreignValue] = $item;
        }

        // Assign to parent models
        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey)->unwrapOr(null);
            $model->setRelation($name, $dictionary[$key] ?? null);
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
        $attributes[$this->foreignKey] = $this->parent->getAttribute($this->localKey)->unwrapOr(null);
        return ($this->related)::create($attributes);
    }

    /**
     * Associate a related model.
     *
     * @param TRelated $model
     */
    public function save(Model $model): Model
    {
        $model->setAttribute($this->foreignKey, $this->parent->getAttribute($this->localKey)->unwrapOr(null));
        $model->save()->match(ok: fn () => null, err: fn ($e) => throw $e);
        return $model;
    }
}
