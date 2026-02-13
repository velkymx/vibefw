<?php

declare(strict_types=1);

namespace Fw\Model;

use Fw\Database\Connection;
use Fw\Database\QueryBuilder;
use Fw\Support\Arr;
use Fw\Support\Option;
use Fw\Support\Str;
use Fw\Model\MassAssignmentException;

/**
 * Base Model class with Active Record pattern.
 *
 * Provides a fluent, type-safe interface for database operations.
 * Uses PHP 8.4 features for property hooks and asymmetric visibility.
 *
 * @example
 *     class User extends Model
 *     {
 *         public UserId $id { set(string|UserId $v) => UserId::wrap($v); }
 *         public Email $email { set(string|Email $v) => Email::wrap($v); }
 *         public string $name;
 *         public private(set) Carbon $createdAt;
 *
 *         public function posts(): HasMany
 *         {
 *             return $this->hasMany(Post::class);
 *         }
 *     }
 *
 *     // Usage
 *     $user = User::find($id);
 *     $users = User::where('active', true)->get();
 *     $user = User::create(['email' => 'a@b.com', 'name' => 'Test']);
 */
abstract class Model
{
    /**
     * The database connection instance.
     */
    protected static ?Connection $connection = null;

    /**
     * The table name. If not set, derived from class name.
     */
    protected static ?string $table = null;

    /**
     * The primary key column.
     */
    protected static string $primaryKey = 'id';

    /**
     * Indicates if the model uses auto-incrementing IDs.
     */
    protected static bool $incrementing = true;

    /**
     * The primary key type ('int' or 'string').
     */
    protected static string $keyType = 'int';

    /**
     * Indicates if the model should be timestamped.
     */
    protected static bool $timestamps = true;

    /**
     * The created_at column name.
     */
    protected static string $createdAtColumn = 'created_at';

    /**
     * The updated_at column name.
     */
    protected static string $updatedAtColumn = 'updated_at';

    /**
     * The attributes that are mass assignable.
     */
    protected static array $fillable = [];

    /**
     * The attributes that are NOT mass assignable.
     */
    protected static array $guarded = ['id'];

    /**
     * Require explicit $fillable definition (strict mode).
     * When true, models without $fillable will reject all mass assignment.
     */
    protected static bool $strictFillable = false;

    /**
     * Global strict mode for all models (set during bootstrap).
     */
    private static bool $globalStrictMode = false;

    /**
     * Attribute casting rules. Maps column => class or type.
     * If empty, auto-detected from property types.
     */
    protected static array $casts = [];

    /**
     * Cached model metadata (per class).
     * @var array<class-string, ModelMetadata>
     */
    private static array $metadataCache = [];

    /**
     * Classes currently being initialized with their initialization tokens.
     * Token is used to detect ownership in concurrent Fiber scenarios.
     * @var array<class-string, int>
     */
    private static array $metadataInitializing = [];

    /**
     * Clear the metadata cache.
     *
     * Call this between requests in persistent runtimes (FrankenPHP worker mode,
     * RoadRunner, etc.) to prevent stale metadata from leaking between requests.
     *
     * @param class-string|null $class Clear cache for specific class, or all if null
     */
    public static function clearMetadataCache(?string $class = null): void
    {
        if ($class !== null) {
            unset(self::$metadataCache[$class]);
            unset(self::$metadataInitializing[$class]);
        } else {
            self::$metadataCache = [];
            self::$metadataInitializing = [];
        }
    }

    /**
     * Enable strict fillable mode globally.
     *
     * In strict mode, models without explicit $fillable definition
     * will reject ALL mass assignment attempts. This prevents
     * accidental mass assignment vulnerabilities.
     *
     * Recommended: Enable in bootstrap for production.
     */
    public static function enableStrictMode(): void
    {
        self::$globalStrictMode = true;
    }

    /**
     * Disable strict fillable mode globally.
     */
    public static function disableStrictMode(): void
    {
        self::$globalStrictMode = false;
    }

    /**
     * Check if strict mode is enabled.
     */
    public static function isStrictMode(): bool
    {
        return self::$globalStrictMode;
    }

    /**
     * The model's original attributes (from database).
     * @var array<string, mixed>
     */
    protected array $original = [];

    /**
     * The model's current attributes.
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * Indicates if the model exists in the database.
     */
    public private(set) bool $exists = false;

    /**
     * Loaded relationships.
     * @var array<string, mixed>
     */
    protected array $relations = [];

    /**
     * Create a new model instance.
     *
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    // ========================================
    // CONNECTION MANAGEMENT
    // ========================================

    /**
     * Set the database connection for all models.
     */
    public static function setConnection(Connection $connection): void
    {
        static::$connection = $connection;
    }

    /**
     * Get the database connection.
     */
    public static function getConnection(): Connection
    {
        if (static::$connection === null) {
            throw new \RuntimeException('No database connection set. Call Model::setConnection() first.');
        }

        return static::$connection;
    }

    /**
     * Resolve connection - requires explicit setConnection() call during bootstrap.
     *
     * This method no longer uses service locator pattern. The connection must be
     * explicitly set via Model::setConnection() during application bootstrap.
     */
    protected static function resolveConnection(): Connection
    {
        if (static::$connection === null) {
            throw new \RuntimeException(
                'No database connection set. Call Model::setConnection() during application bootstrap.'
            );
        }

        return static::$connection;
    }

    // ========================================
    // TABLE & METADATA
    // ========================================

    /**
     * Get the table name for this model.
     */
    public static function getTable(): string
    {
        if (static::$table !== null) {
            return static::$table;
        }

        // Derive from class name: User -> users, BlogPost -> blog_posts
        $class = (new \ReflectionClass(static::class))->getShortName();
        return Str::snake(Str::plural($class));
    }

    /**
     * Get model metadata (cached).
     *
     * Thread-safe for Fiber concurrency using atomic initialization pattern.
     * If multiple Fibers attempt to initialize simultaneously, the operation
     * is idempotent - the second initialization simply overwrites with
     * identical data, which is safe.
     */
    protected static function metadata(): ModelMetadata
    {
        $class = static::class;

        // Fast path: already cached (most common case)
        if (isset(self::$metadataCache[$class])) {
            return self::$metadataCache[$class];
        }

        // Atomic claim: try to mark this class as initializing
        // Use a unique token to detect if WE are the initializer
        $initToken = spl_object_id(new \stdClass());

        // If already being initialized by another Fiber, wait for it
        $spinCount = 0;
        $maxSpins = 1000;

        while (true) {
            // Check cache first (another Fiber may have finished)
            if (isset(self::$metadataCache[$class])) {
                return self::$metadataCache[$class];
            }

            // Try to claim initialization
            if (!isset(self::$metadataInitializing[$class])) {
                // Claim it with our token
                self::$metadataInitializing[$class] = $initToken;
                break;
            }

            // Another Fiber is initializing - wait
            if (++$spinCount > $maxSpins) {
                // Timeout: force proceed (initialization is idempotent anyway)
                // This handles edge cases like a Fiber dying mid-initialization
                self::$metadataInitializing[$class] = $initToken;
                break;
            }

            // Yield to other Fibers
            if (\Fiber::getCurrent() !== null) {
                \Fiber::suspend();
            } else {
                // Not in a Fiber, just a tight loop - add small delay
                usleep(100);
            }
        }

        try {
            // Verify we still own the initialization (another Fiber didn't steal it)
            // If cache is now set, another Fiber beat us - use their result
            if (isset(self::$metadataCache[$class])) {
                return self::$metadataCache[$class];
            }

            // We own initialization - create metadata
            // This is idempotent: if two Fibers both reach here, they produce identical results
            $metadata = new ModelMetadata(
                class: $class,
                table: static::getTable(),
                primaryKey: static::$primaryKey,
                incrementing: static::$incrementing,
                keyType: static::$keyType,
                timestamps: static::$timestamps,
                createdAtColumn: static::$createdAtColumn,
                updatedAtColumn: static::$updatedAtColumn,
                fillable: static::$fillable,
                guarded: static::$guarded,
                casts: static::$casts,
            );

            // Store in cache
            self::$metadataCache[$class] = $metadata;

            return $metadata;
        } finally {
            // Only clear the flag if we set it (check our token)
            if ((self::$metadataInitializing[$class] ?? null) === $initToken) {
                unset(self::$metadataInitializing[$class]);
            }
        }
    }

    // ========================================
    // STATIC QUERY METHODS
    // ========================================

    /**
     * Begin a new query for this model.
     */
    public static function query(): ModelQueryBuilder
    {
        return new ModelQueryBuilder(
            static::resolveConnection(),
            static::class,
            static::metadata()
        );
    }

    /**
     * Find a model by its primary key.
     *
     * @return Option<static>
     */
    public static function find(mixed $id): Option
    {
        return static::query()->find($id);
    }

    /**
     * Find a model by its primary key or throw.
     *
     * @throws ModelNotFoundException
     */
    public static function findOrFail(mixed $id): static
    {
        return static::find($id)->unwrapOrElse(
            fn() => throw ModelNotFoundException::forModel(static::class, $id)
        );
    }

    /**
     * Find multiple models by their primary keys.
     *
     * @param array<mixed> $ids
     * @return Collection<static>
     */
    public static function findMany(array $ids): Collection
    {
        return static::query()->whereIn(static::$primaryKey, $ids)->get();
    }

    /**
     * Get all models.
     *
     * @return Collection<static>
     */
    public static function all(): Collection
    {
        return static::query()->get();
    }

    /**
     * Get the first model.
     *
     * @return Option<static>
     */
    public static function first(): Option
    {
        return static::query()->first();
    }

    /**
     * Add a where clause.
     */
    public static function where(string $column, mixed $operator = null, mixed $value = null): ModelQueryBuilder
    {
        return static::query()->where($column, $operator, $value);
    }

    /**
     * Add a whereIn clause.
     */
    public static function whereIn(string $column, array $values): ModelQueryBuilder
    {
        return static::query()->whereIn($column, $values);
    }

    /**
     * Add a whereNull clause.
     */
    public static function whereNull(string $column): ModelQueryBuilder
    {
        return static::query()->whereNull($column);
    }

    /**
     * Add a whereNotNull clause.
     */
    public static function whereNotNull(string $column): ModelQueryBuilder
    {
        return static::query()->whereNotNull($column);
    }

    /**
     * Order by a column.
     */
    public static function orderBy(string $column, string $direction = 'asc'): ModelQueryBuilder
    {
        return static::query()->orderBy($column, $direction);
    }

    /**
     * Limit the query.
     */
    public static function limit(int $limit): ModelQueryBuilder
    {
        return static::query()->limit($limit);
    }

    /**
     * Eager load relationships.
     */
    public static function with(string|array $relations): ModelQueryBuilder
    {
        return static::query()->with($relations);
    }

    /**
     * Get the count of models.
     */
    public static function count(): int
    {
        return static::query()->count();
    }

    /**
     * Check if any models exist.
     */
    public static function exists(): bool
    {
        return static::query()->exists();
    }

    /**
     * Pluck a single column.
     *
     * @return array<mixed>
     */
    public static function pluck(string $column, ?string $key = null): array
    {
        return static::query()->pluck($column, $key);
    }

    // ========================================
    // STATIC MUTATION METHODS
    // ========================================

    /**
     * Create a new model and save it.
     *
     * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    /**
     * Update or create a model.
     *
     * @param array<string, mixed> $attributes Attributes to search by
     * @param array<string, mixed> $values Attributes to update/create with
     */
    public static function updateOrCreate(array $attributes, array $values = []): static
    {
        $model = static::where(array_key_first($attributes), $attributes[array_key_first($attributes)]);

        foreach (array_slice($attributes, 1) as $key => $value) {
            $model = $model->where($key, $value);
        }

        $existing = $model->first();

        if ($existing->isSome()) {
            $instance = $existing->unwrap();
            $instance->fill($values);
            $instance->save();
            return $instance;
        }

        return static::create(array_merge($attributes, $values));
    }

    /**
     * Find or create a model.
     *
     * @param array<string, mixed> $attributes Attributes to search by
     * @param array<string, mixed> $values Additional attributes for creation
     */
    public static function firstOrCreate(array $attributes, array $values = []): static
    {
        $query = static::query();

        foreach ($attributes as $key => $value) {
            $query = $query->where($key, $value);
        }

        $existing = $query->first();

        if ($existing->isSome()) {
            return $existing->unwrap();
        }

        return static::create(array_merge($attributes, $values));
    }

    /**
     * Delete models by primary key.
     *
     * @param mixed $ids Single ID or array of IDs
     */
    public static function destroy(mixed $ids): int
    {
        $ids = is_array($ids) ? $ids : [$ids];

        return static::query()
            ->whereIn(static::$primaryKey, $ids)
            ->delete();
    }

    // ========================================
    // INSTANCE METHODS
    // ========================================

    /**
     * Fill the model with attributes.
     *
     * In strict mode, attempting to fill non-fillable attributes will throw.
     * In non-strict mode, non-fillable attributes are silently ignored.
     *
     * @param array<string, mixed> $attributes
     * @throws MassAssignmentException If strict mode is enabled and non-fillable attributes are passed
     */
    public function fill(array $attributes): static
    {
        $metadata = static::metadata();

        // First pass: validate all attributes before modifying any state
        // This prevents partial state corruption if exception is thrown
        $fillable = [];
        $rejected = [];

        foreach ($attributes as $key => $value) {
            if ($metadata->isFillable($key)) {
                $fillable[$key] = $value;
            } else {
                $rejected[] = $key;
            }
        }

        // In strict mode, reject if any non-fillable attributes were passed
        // Check BEFORE making any changes to model state
        if (!empty($rejected) && $this->shouldEnforceStrictFillable()) {
            throw MassAssignmentException::forAttributes(static::class, $rejected);
        }

        // Second pass: apply validated attributes
        foreach ($fillable as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    /**
     * Fill the model without mass assignment protection.
     *
     * Use this ONLY for trusted data (e.g., seeding, internal operations).
     * NEVER use with user input.
     *
     * @param array<string, mixed> $attributes
     */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    /**
     * Check if strict fillable should be enforced for this model.
     */
    protected function shouldEnforceStrictFillable(): bool
    {
        // Model-level strict mode takes precedence
        if (static::$strictFillable) {
            return true;
        }

        // Global strict mode
        return self::$globalStrictMode;
    }

    /**
     * Set an attribute value.
     */
    public function setAttribute(string $key, mixed $value): static
    {
        // Convert to snake_case for storage
        $key = Str::snake($key);

        // Cast the value
        $value = $this->castAttribute($key, $value);

        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Get an attribute value.
     */
    public function getAttribute(string $key): mixed
    {
        $key = Str::snake($key);

        // Check attributes first
        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }

        // Check relations
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        // Try to load relationship
        $camelKey = Str::camel($key);
        if (method_exists($this, $camelKey)) {
            return $this->getRelation($camelKey);
        }

        return null;
    }

    /**
     * Cast an attribute to the appropriate type.
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        $metadata = static::metadata();
        $castType = $metadata->getCastType($key);

        if ($value === null) {
            // For array cast, return empty array instead of null
            if ($castType === 'array') {
                return [];
            }
            return null;
        }

        if ($castType === null) {
            return $value;
        }

        // Handle built-in types
        if (is_string($castType)) {
            return match ($castType) {
                'int', 'integer' => (int) $value,
                'float', 'double', 'real' => (float) $value,
                'string' => (string) $value,
                'bool', 'boolean' => (bool) $value,
                'array' => is_array($value) ? $value : json_decode($value, true),
                'json', 'object' => is_string($value) ? json_decode($value, true) : $value,
                'datetime', 'date' => $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable($value),
                default => $this->castToClass($castType, $value),
            };
        }

        return $value;
    }

    /**
     * Cast a value to a class instance.
     */
    protected function castToClass(string $class, mixed $value): mixed
    {
        if ($value instanceof $class) {
            return $value;
        }

        // Try static wrap() method (Value Objects)
        if (method_exists($class, 'wrap')) {
            return $class::wrap($value);
        }

        // Try static from() method
        if (method_exists($class, 'from')) {
            return $class::from($value);
        }

        // Try static fromTrusted() method
        if (method_exists($class, 'fromTrusted')) {
            return $class::fromTrusted($value);
        }

        // Try constructor
        return new $class($value);
    }

    /**
     * Dehydrate an attribute for storage.
     */
    protected function dehydrateAttribute(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // Value objects with value property
        if (is_object($value) && property_exists($value, 'value')) {
            return $value->value;
        }

        // Objects with __toString
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        // DateTimeInterface
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        // Arrays/objects to JSON
        if (is_array($value) || is_object($value)) {
            $metadata = static::metadata();
            $castType = $metadata->getCastType($key);

            if (in_array($castType, ['array', 'json', 'object'])) {
                return json_encode($value);
            }
        }

        return $value;
    }

    /**
     * Get the primary key column name.
     */
    public static function getKeyName(): string
    {
        return static::$primaryKey;
    }

    /**
     * Get the primary key value.
     */
    public function getKey(): mixed
    {
        return $this->getAttribute(static::$primaryKey);
    }

    /**
     * Check if the model has been modified.
     */
    public function isDirty(?string $key = null): bool
    {
        if ($key !== null) {
            $key = Str::snake($key);
            return ($this->attributes[$key] ?? null) !== ($this->original[$key] ?? null);
        }

        return $this->attributes !== $this->original;
    }

    /**
     * Get the changed attributes.
     *
     * @return array<string, mixed>
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $value !== $this->original[$key]) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    /**
     * Save the model to the database.
     */
    public function save(): bool
    {
        $connection = static::resolveConnection();

        if ($this->exists) {
            return $this->performUpdate($connection);
        }

        return $this->performInsert($connection);
    }

    /**
     * Perform an insert operation.
     */
    protected function performInsert(Connection $connection): bool
    {
        $metadata = static::metadata();

        // Generate UUID for non-incrementing string keys
        if (!$metadata->incrementing && $metadata->keyType === 'string') {
            if (!isset($this->attributes[$metadata->primaryKey]) || $this->attributes[$metadata->primaryKey] === null) {
                $this->attributes[$metadata->primaryKey] = \Fw\Support\Str::uuid();
            }
        }

        // Set timestamps
        if ($metadata->timestamps) {
            $now = date('Y-m-d H:i:s');

            if (!isset($this->attributes[$metadata->createdAtColumn])) {
                $this->attributes[$metadata->createdAtColumn] = $now;
            }

            if (!isset($this->attributes[$metadata->updatedAtColumn])) {
                $this->attributes[$metadata->updatedAtColumn] = $now;
            }
        }

        // Prepare data for insertion
        $data = [];
        foreach ($this->attributes as $key => $value) {
            $data[$key] = $this->dehydrateAttribute($key, $value);
        }

        $id = $connection->insert($metadata->table, $data);

        // Set the primary key if auto-incrementing
        if ($metadata->incrementing && $id > 0) {
            $this->attributes[$metadata->primaryKey] = $id;
        }

        $this->exists = true;
        $this->original = $this->attributes;

        return true;
    }

    /**
     * Perform an update operation.
     */
    protected function performUpdate(Connection $connection): bool
    {
        $dirty = $this->getDirty();

        if (empty($dirty)) {
            return true; // Nothing to update
        }

        $metadata = static::metadata();

        // Update timestamp
        if ($metadata->timestamps) {
            $dirty[$metadata->updatedAtColumn] = date('Y-m-d H:i:s');
            $this->attributes[$metadata->updatedAtColumn] = $dirty[$metadata->updatedAtColumn];
        }

        // Prepare data
        $data = [];
        foreach ($dirty as $key => $value) {
            $data[$key] = $this->dehydrateAttribute($key, $value);
        }

        $connection->update(
            $metadata->table,
            $data,
            [$metadata->primaryKey => $this->dehydrateAttribute($metadata->primaryKey, $this->getKey())]
        );

        $this->original = $this->attributes;

        return true;
    }

    /**
     * Delete the model from the database.
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $connection = static::resolveConnection();
        $metadata = static::metadata();

        $connection->delete(
            $metadata->table,
            [$metadata->primaryKey => $this->dehydrateAttribute($metadata->primaryKey, $this->getKey())]
        );

        $this->exists = false;

        return true;
    }

    /**
     * Refresh the model from the database.
     */
    public function refresh(): static
    {
        if (!$this->exists) {
            return $this;
        }

        $fresh = static::find($this->getKey());

        if ($fresh->isSome()) {
            $this->attributes = $fresh->unwrap()->attributes;
            $this->original = $this->attributes;
            $this->relations = [];
        }

        return $this;
    }

    /**
     * Create a fresh copy with new attributes.
     *
     * @param array<string, mixed> $attributes
     */
    public function replicate(array $attributes = []): static
    {
        $clone = new static($this->attributes);
        unset($clone->attributes[static::$primaryKey]);
        $clone->exists = false;
        $clone->fill($attributes);

        return $clone;
    }

    // ========================================
    // RELATIONSHIPS
    // ========================================

    /**
     * Define a has-one relationship.
     *
     * @param class-string<Model> $related
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $foreignKey ??= Str::snake((new \ReflectionClass($this))->getShortName()) . '_id';
        $localKey ??= static::$primaryKey;

        return new HasOne($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define a has-many relationship.
     *
     * @param class-string<Model> $related
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $foreignKey ??= Str::snake((new \ReflectionClass($this))->getShortName()) . '_id';
        $localKey ??= static::$primaryKey;

        return new HasMany($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define a belongs-to relationship.
     *
     * @param class-string<Model> $related
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $foreignKey ??= Str::snake((new \ReflectionClass($related))->getShortName()) . '_id';
        $ownerKey ??= $related::$primaryKey;

        return new BelongsTo($this, $related, $foreignKey, $ownerKey);
    }

    /**
     * Define a many-to-many relationship.
     *
     * @param class-string<Model> $related
     */
    protected function belongsToMany(
        string $related,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
    ): BelongsToMany {
        // Derive table name from model names (alphabetically)
        if ($table === null) {
            $models = [
                Str::snake((new \ReflectionClass($this))->getShortName()),
                Str::snake((new \ReflectionClass($related))->getShortName()),
            ];
            sort($models);
            $table = implode('_', $models);
        }

        $foreignPivotKey ??= Str::snake((new \ReflectionClass($this))->getShortName()) . '_id';
        $relatedPivotKey ??= Str::snake((new \ReflectionClass($related))->getShortName()) . '_id';

        return new BelongsToMany($this, $related, $table, $foreignPivotKey, $relatedPivotKey);
    }

    /**
     * Get a loaded relationship or load it.
     */
    protected function getRelation(string $name): mixed
    {
        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }

        if (method_exists($this, $name)) {
            $relation = $this->$name();

            if ($relation instanceof Relation) {
                $this->relations[$name] = $relation->get();
                return $this->relations[$name];
            }
        }

        return null;
    }

    /**
     * Set a loaded relationship.
     */
    public function setRelation(string $name, mixed $value): static
    {
        $this->relations[$name] = $value;
        return $this;
    }

    // ========================================
    // HYDRATION
    // ========================================

    /**
     * Create a model instance from database row.
     *
     * @param array<string, mixed> $attributes
     */
    public static function hydrate(array $attributes): static
    {
        $model = new static();
        $model->exists = true;

        foreach ($attributes as $key => $value) {
            $model->attributes[$key] = $model->castAttribute($key, $value);
        }

        $model->original = $model->attributes;

        // Set public properties from attributes
        $reflection = new \ReflectionClass($model);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $propName = $prop->getName();
            if (array_key_exists($propName, $model->attributes)) {
                $model->{$propName} = $model->attributes[$propName];
            }
        }

        return $model;
    }

    /**
     * Create collection of models from database rows.
     *
     * @param array<array<string, mixed>> $rows
     * @return Collection<static>
     */
    public static function hydrateMany(array $rows): Collection
    {
        return new Collection(array_map(
            fn($row) => static::hydrate($row),
            $rows
        ));
    }

    // ========================================
    // ARRAY / JSON
    // ========================================

    /**
     * Convert model to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [];

        foreach ($this->attributes as $key => $value) {
            $array[$key] = $this->dehydrateAttribute($key, $value);
        }

        // Include loaded relations
        foreach ($this->relations as $key => $value) {
            if ($value instanceof Collection) {
                $array[$key] = $value->toArray();
            } elseif ($value instanceof Model) {
                $array[$key] = $value->toArray();
            } else {
                $array[$key] = $value;
            }
        }

        return $array;
    }

    /**
     * Convert model to JSON.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    // ========================================
    // MAGIC METHODS
    // ========================================

    public function __get(string $name): mixed
    {
        return $this->getAttribute($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    public function __isset(string $name): bool
    {
        return $this->getAttribute($name) !== null;
    }

    public function __unset(string $name): void
    {
        $key = Str::snake($name);
        unset($this->attributes[$key], $this->relations[$name]);
    }
}
