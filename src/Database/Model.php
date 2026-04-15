<?php

declare(strict_types=1);

namespace Fw\Database;

use ReflectionClass;
use RuntimeException;

/**
 * Legacy Model class - use Fw\Model\Model instead for new code.
 *
 * @deprecated Use Fw\Model\Model for new development. This class is kept
 *             for backward compatibility only.
 */
abstract class Model
{
    /**
     * The database connection (set during bootstrap via setConnection()).
     */
    protected static ?Connection $connection = null;

    protected static string $table = '';

    protected static string $primaryKey = 'id';

    protected static bool $timestamps = true;

    protected static bool $softDeletes = false;

    protected array $attributes = [];

    protected array $original = [];

    protected bool $exists = false;

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
        $this->original = $this->attributes;
    }

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
        return isset($this->attributes[$name]);
    }

    /**
     * Set the database connection for all legacy models.
     */
    public static function setConnection(Connection $connection): void
    {
        static::$connection = $connection;
    }

    public static function query(): QueryBuilder
    {
        $query = static::connection()->table(static::getTable());

        if (static::$softDeletes) {
            $query = $query->whereNull('deleted_at');
        }

        return $query;
    }

    public static function all(): array
    {
        $rows = static::query()->get();
        return array_map(fn ($row) => static::hydrate($row), $rows);
    }

    public static function find(int|string $id): ?static
    {
        $rowOpt = static::query()->where(static::$primaryKey, $id)->first();

        if ($rowOpt->isNone()) {
            return null;
        }

        return static::hydrate($rowOpt->unwrapOrElse(fn () => throw new \LogicException('unreachable')));
    }

    public static function findOrFail(int|string $id): static
    {
        $model = static::find($id);

        if ($model === null) {
            throw new RuntimeException('Model not found');
        }

        return $model;
    }

    public static function where(string $column, mixed $operator = null, mixed $value = null): QueryBuilder
    {
        return static::query()->where($column, $operator, $value);
    }

    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    protected static function getTable(): string
    {
        if (!empty(static::$table)) {
            return static::$table;
        }

        $class = new ReflectionClass(static::class)->getShortName();
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class)) . 's';
    }

    protected static function connection(): Connection
    {
        if (static::$connection === null) {
            throw new RuntimeException(
                'No database connection set. Call Model::setConnection() during application bootstrap.'
            );
        }

        return static::$connection;
    }

    protected static function hydrate(array $attributes): static
    {
        $model = new static();
        $model->attributes = $attributes;
        $model->exists = true;
        $model->original = $attributes;
        return $model;
    }

    public function getAttribute(string $name): mixed
    {
        $method = 'get' . $this->studly($name) . 'Attribute';

        if (method_exists($this, $method)) {
            return $this->$method($this->attributes[$name] ?? null);
        }

        return $this->attributes[$name] ?? null;
    }

    public function setAttribute(string $name, mixed $value): self
    {
        $method = 'set' . $this->studly($name) . 'Attribute';

        if (method_exists($this, $method)) {
            $value = $this->$method($value);
        }

        $this->attributes[$name] = $value;
        return $this;
    }

    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function isDirty(?string $key = null): bool
    {
        if ($key !== null) {
            return ($this->attributes[$key] ?? null) !== ($this->original[$key] ?? null);
        }

        return $this->attributes !== $this->original;
    }

    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    public function save(): bool
    {
        if ($this->exists) {
            return $this->performUpdate();
        }

        return $this->performInsert();
    }

    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $pk = $this->attributes[static::$primaryKey] ?? null;

        if ($pk === null) {
            return false;
        }

        if (static::$softDeletes) {
            $now = date('Y-m-d H:i:s');
            $this->attributes['deleted_at'] = $now;

            static::connection()->update(
                static::getTable(),
                ['deleted_at' => $now],
                [static::$primaryKey => $pk]
            );
        } else {
            static::connection()->delete(
                static::getTable(),
                [static::$primaryKey => $pk]
            );
        }

        $this->exists = false;

        return true;
    }

    public function forceDelete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $pk = $this->attributes[static::$primaryKey] ?? null;

        if ($pk === null) {
            return false;
        }

        static::connection()->delete(
            static::getTable(),
            [static::$primaryKey => $pk]
        );

        $this->exists = false;

        return true;
    }

    public function refresh(): self
    {
        if (!$this->exists) {
            return $this;
        }

        $pk = $this->attributes[static::$primaryKey] ?? null;

        if ($pk !== null) {
            $freshOpt = static::connection()->table(static::getTable())
                ->where(static::$primaryKey, $pk)
                ->first();

            $fresh = $freshOpt->unwrapOr(null);
            if ($fresh !== null) {
                $this->attributes = $fresh;
                $this->original = $fresh;
            }
        }

        return $this;
    }

    protected function performInsert(): bool
    {
        $attributes = $this->attributes;

        if (static::$timestamps) {
            $now = date('Y-m-d H:i:s');
            $attributes['created_at'] = $now;
            $attributes['updated_at'] = $now;
            $this->attributes['created_at'] = $now;
            $this->attributes['updated_at'] = $now;
        }

        $id = static::connection()->insert(static::getTable(), $attributes);

        if ($id > 0) {
            $this->attributes[static::$primaryKey] = $id;
        }

        $this->exists = true;
        $this->original = $this->attributes;

        return true;
    }

    protected function performUpdate(): bool
    {
        $dirty = $this->getDirty();

        if (empty($dirty)) {
            return true;
        }

        if (static::$timestamps) {
            $dirty['updated_at'] = date('Y-m-d H:i:s');
            $this->attributes['updated_at'] = $dirty['updated_at'];
        }

        static::connection()->update(
            static::getTable(),
            $dirty,
            [static::$primaryKey => $this->attributes[static::$primaryKey]]
        );

        $this->original = $this->attributes;

        return true;
    }

    protected function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }
}
