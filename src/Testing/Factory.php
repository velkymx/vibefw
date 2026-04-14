<?php

declare(strict_types=1);

namespace Fw\Testing;

use Fw\Model\Model;
use Fw\Support\Str;
use ReflectionClass;

/**
 * Base Factory class for generating test data.
 *
 * Factories provide a convenient way to generate model instances
 * for testing without manually specifying every attribute.
 *
 * Usage:
 *     // Create factory for a model
 *     class UserFactory extends Factory
 *     {
 *         protected string $model = User::class;
 *
 *         public function definition(): array
 *         {
 *             return [
 *                 'name' => $this->faker->name(),
 *                 'email' => $this->faker->unique()->email(),
 *                 'password' => 'password',
 *             ];
 *         }
 *     }
 *
 *     // Usage in tests
 *     $user = UserFactory::new()->create();
 *     $users = UserFactory::new()->count(10)->create();
 *     $admin = UserFactory::new()->create(['role' => 'admin']);
 *
 * @template TModel of Model
 */
abstract class Factory
{
    /**
     * Sequence counter for unique values.
     */
    private static int $sequence = 0;

    /**
     * The model class to generate.
     * @var class-string<TModel>
     */
    protected string $model;

    /**
     * Number of models to create.
     */
    protected int $count = 1;

    /**
     * State modifications to apply.
     * @var array<callable>
     */
    protected array $states = [];

    /**
     * Attributes to override.
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * Related model to associate.
     */
    protected ?Model $for = null;

    /**
     * Foreign key for the relation.
     */
    protected ?string $foreignKey = null;

    /**
     * Simple faker for generating data.
     */
    protected FakerGenerator $faker;

    public function __construct()
    {
        $this->faker = new FakerGenerator();
    }

    /**
     * Create a new factory instance.
     *
     * @return static
     */
    public static function new(): static
    {
        return new static();
    }

    /**
     * Reset the sequence counter.
     */
    public static function resetSequence(): void
    {
        self::$sequence = 0;
    }

    /**
     * Define the default model attributes.
     *
     * @return array<string, mixed>
     */
    abstract public function definition(): array;

    /**
     * Set the number of models to create.
     *
     * @return static
     */
    public function count(int $count): static
    {
        $clone = clone $this;
        $clone->count = $count;
        return $clone;
    }

    /**
     * Override specific attributes.
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public function state(array $attributes): static
    {
        $clone = clone $this;
        $clone->attributes = array_merge($clone->attributes, $attributes);
        return $clone;
    }

    /**
     * Associate with a parent model.
     *
     * @return static
     */
    public function for(Model $model, ?string $foreignKey = null): static
    {
        $clone = clone $this;
        $clone->for = $model;
        $clone->foreignKey = $foreignKey;
        return $clone;
    }

    /**
     * Create model(s) and persist to database.
     *
     * @param array<string, mixed> $attributes
     * @return TModel|array<TModel>
     */
    public function create(array $attributes = []): Model|array
    {
        if ($this->count === 1) {
            return $this->createOne($attributes);
        }

        $models = [];
        for ($i = 0; $i < $this->count; $i++) {
            $models[] = $this->createOne($attributes);
        }

        return $models;
    }

    /**
     * Make model(s) without persisting.
     *
     * @param array<string, mixed> $attributes
     * @return TModel|array<TModel>
     */
    public function make(array $attributes = []): Model|array
    {
        if ($this->count === 1) {
            return $this->makeOne($attributes);
        }

        $models = [];
        for ($i = 0; $i < $this->count; $i++) {
            $models[] = $this->makeOne($attributes);
        }

        return $models;
    }

    /**
     * Create a single model instance.
     *
     * @param array<string, mixed> $attributes
     * @return TModel
     */
    protected function createOne(array $attributes = []): Model
    {
        $data = $this->makeAttributes($attributes);

        /** @var TModel $model */
        $model = new $this->model();
        $model->forceFill($data);
        $model->save()->match(ok: fn () => null, err: fn ($e) => throw $e);

        return $model;
    }

    /**
     * Make a single model without persisting.
     *
     * @param array<string, mixed> $attributes
     * @return TModel
     */
    protected function makeOne(array $attributes = []): Model
    {
        $data = $this->makeAttributes($attributes);

        /** @var TModel $model */
        $model = new $this->model();
        $model->forceFill($data);

        return $model;
    }

    /**
     * Build the final attributes array.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function makeAttributes(array $attributes = []): array
    {
        // Start with definition
        $data = $this->definition();

        // Apply states
        foreach ($this->states as $state) {
            $data = array_merge($data, $state($data));
        }

        // Apply instance attributes
        $data = array_merge($data, $this->attributes);

        // Apply passed attributes
        $data = array_merge($data, $attributes);

        // Apply parent relation
        if ($this->for !== null) {
            $foreignKey = $this->foreignKey ?? $this->guessForeignKey();
            $data[$foreignKey] = $this->for->getKey();
        }

        // Resolve callables
        foreach ($data as $key => $value) {
            if (is_callable($value) && !is_string($value)) {
                $data[$key] = $value();
            }
        }

        return $data;
    }

    /**
     * Guess the foreign key name from the parent model.
     */
    protected function guessForeignKey(): string
    {
        if ($this->for === null) {
            return 'parent_id';
        }

        $class = new ReflectionClass($this->for)->getShortName();
        return Str::snake($class) . '_id';
    }

    /**
     * Get the next sequence number.
     */
    protected function sequence(): int
    {
        return ++self::$sequence;
    }
}
