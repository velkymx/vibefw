# START HERE

Best practices for this part of the codebase are to use the following CLI commands.

- `php fw make:model Post` — create a model
- `php fw make:model Post -m` — model + matching migration in one step
- `php fw make:factory Post` — generate a model factory for tests/seeders
- `php fw add:field Post status string` — append a field to an existing model + migration + schema
- `php fw make:link Post Comment --hasMany` — wire a relationship between two models
- `php fw model:inspect Post` — dump table, fillable, casts, and relationships
- `php fw make:resource --schema=app/Schemas/Post.json` — generate model + controller + migration + requests + views from one JSON schema

# BEWARE

Only read past here if you are unable to use the CLI.

# Models

Models represent database tables using the Active Record pattern. They live in `app/Models/`.

## Creating a Model

```bash
php fw make:model Post       # Model only
php fw make:model Post -m    # Model + matching migration (--migration)
```

To generate a model alongside its controller, views, form requests, and factory in one step, use the schema workflow — see [schema.md](schema.md). For migration syntax and column types see [database.md](database.md).

## Inspecting a Model

Before writing code, inspect what the framework knows about your model:

```bash
php fw model:inspect Post
# Shows: table name, all columns + types, $fillable, $casts, relations, row count, indexes
```

Use this to confirm your migration ran, your fillable fields are correct, and your relations are wired up before writing controller or view code.

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Fw\Model\Model;

class Post extends Model
{
    protected static ?string $table = 'posts';

    protected static array $fillable = [
        'title',
        'content',
        'user_id',
        'published_at',
    ];

    protected static array $casts = [
        'published_at' => 'datetime',
        'view_count'   => 'int',
        'is_featured'  => 'bool',
    ];
}
```

## Model Configuration

### Table Name

```php
protected static ?string $table = 'posts';
```

If not set, derived by converting the class name to snake_case plural:
- `Post` → `posts`
- `BlogPost` → `blog_posts`
- `UserProfile` → `user_profiles`

Always set `$table` explicitly — auto-derivation exists as a fallback only.

### Primary Key

```php
protected static string $primaryKey = 'id';      // Default
protected static string $keyType = 'int';         // 'int' or 'string'
protected static bool $incrementing = true;       // Auto-increment
```

For UUIDs:

```php
protected static bool $incrementing = false;
protected static string $keyType = 'string';
```

### Fillable Fields

Every model **must** declare `$fillable`. **`$guarded` does not exist in this framework** — do not use it. Strict mode is permanently on: any field not in `$fillable` is silently dropped on `create()` and `fill()`.

```php
protected static array $fillable = ['title', 'content', 'user_id'];
```

> **Wrong — `$guarded` is silently ignored:**
> ```php
> protected static array $guarded = [];  // ❌ has no effect
> ```

### Type Casting

```php
protected static array $casts = [
    'published_at' => 'datetime',
    'is_active'    => 'bool',
    'view_count'   => 'int',
    'price'        => 'float',
    'metadata'     => 'array',
    'settings'     => 'json',
];
```

Available types: `int`, `float`, `bool`, `string`, `array`, `json`, `datetime`

### Timestamps

```php
protected static bool $timestamps = true;  // Manages created_at, updated_at
```

## Querying

### Basic Retrieval

```php
// All records
$posts = Post::all();

// Find by ID — returns Option, not null (see [result-option.md](result-option.md))
Post::find($id)->match(
    some: fn($post) => $post->title,
    none: fn() => 'Not found',
);

// First matching — returns Option
Post::where('slug', '=', $slug)->first()->match(
    some: fn($post) => $this->view('posts.show', ['post' => $post]),
    none: fn() => $this->notFound(),
);
```

### Query Builder

```php
// Where clauses
$posts = Post::where('status', '=', 'published')->get();
$posts = Post::where('views', '>', 100)->get();

// Or where
$posts = Post::where('status', '=', 'published')
    ->orWhere('featured', '=', true)
    ->get();

// Where in
$posts = Post::whereIn('id', [1, 2, 3])->get();

// Where null / not null
$posts = Post::whereNull('deleted_at')->get();
$posts = Post::whereNotNull('published_at')->get();

// Ordering
$posts = Post::orderBy('created_at', 'desc')->get();
$posts = Post::latest()->get();    // Order by created_at desc
$posts = Post::oldest()->get();    // Order by created_at asc

// Limiting
$posts = Post::limit(10)->get();
$posts = Post::limit(10)->offset(20)->get();

// Count
$count = Post::where('status', '=', 'published')->count();
```

### Conditional Query Building

The QueryBuilder is immutable. Conditional steps require re-assignment:

```php
$query = Post::where('status', '=', 'published');

if ($request->get('category')) {
    $query = $query->where('category_id', '=', $request->get('category'));
}

$posts = $query->orderBy('created_at', 'desc')->get();
```

### Pagination

```php
$pagination = Post::orderBy('created_at', 'desc')->paginate(15, $page);
// Returns: ['items' => [...], 'total' => 100, 'per_page' => 15, 'current_page' => 1, 'last_page' => 7]
```

Opt into a single round-trip using a window function for the total:

```php
$pagination = Post::orderBy('created_at', 'desc')->paginate(15, $page, useWindow: true);
```

The window path emits `COUNT(*) OVER ()` alongside the page query. It falls back to the two-query path when `distinct()` or `groupBy()` would change the window semantics, so it's always safe to request.

### Scopes

```php
class Post extends Model
{
    public static function published(): array
    {
        return static::whereNotNull('published_at')
            ->where('published_at', '<=', date('Y-m-d H:i:s'))
            ->orderBy('published_at', 'desc')
            ->get();
    }

    public static function byAuthor(int $userId): array
    {
        return static::where('user_id', '=', $userId)->get();
    }
}
```

## Creating & Updating

### Create

```php
$post = Post::create([
    'title' => 'My Post',
    'content' => 'Content here...',
    'user_id' => $userId,
]);
```

### Update

```php
Post::find($id)->match(
    some: function ($post) use ($data) {
        $post->fill($data->toArray())->save();
    },
    none: fn() => null,
);

// Mass update
Post::where('status', '=', 'draft')->update(['status' => 'archived']);
```

### Delete

```php
$post->delete();

// Mass delete
Post::where('created_at', '<', $date)->delete();
```

## Relationships

Return types on relationship methods are **required** — they enable eager loading and IDE support. Omitting the return type causes eager loading to silently fail.

### Belongs To

```php
use Fw\Model\Relations\BelongsTo;

public function author(): BelongsTo   // ← return type required
{
    return $this->belongsTo(User::class, 'user_id');
}
```

### Has Many

```php
use Fw\Model\Relations\HasMany;

public function posts(): HasMany
{
    return $this->hasMany(Post::class, 'user_id');
}
```

### Has One

```php
use Fw\Model\Relations\HasOne;

public function profile(): HasOne
{
    return $this->hasOne(Profile::class, 'user_id');
}
```

### Wiring Relationships via CLI

```bash
php fw make:link Post Comment --hasMany
php fw make:link User Profile --hasOne
php fw make:link Post Tag --manyToMany
```

This generates migrations, updates both models, and updates `$fillable`.

### Eager Loading

Without eager loading, accessing a relation inside a loop triggers one query per model (N+1):

```php
// Bad — N+1 queries
$posts = Post::all();
foreach ($posts as $post) {
    echo $post->author->name;  // one query per post
}

// Good — 2 queries total (posts + authors)
$posts = Post::with('author')->get();
foreach ($posts as $post) {
    echo $post->author->name;  // no extra queries
}
```

Eager load multiple relations or nest them:

```php
$posts = Post::with(['author', 'comments'])->get();
$posts = Post::with('author.profile')->get();  // nested
```

## Accessors & Mutators

```php
class User extends Model
{
    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = password_hash($value, PASSWORD_DEFAULT);
    }
}

// Usage
echo $user->full_name;
$user->password = 'plain-text';  // Automatically hashed
```

## Model Events

```php
class Post extends Model
{
    protected function beforeSave(): void
    {
        if (empty($this->slug)) {
            $this->slug = \Fw\Support\Str::slug($this->title);
        }
    }

    protected function afterSave(): void
    {
        // Invalidate cache, etc.
    }

    protected function beforeDelete(): void
    {
        // Cleanup before deletion
    }
}
```

## Serialization

```php
$array = $post->toArray();
$json  = $post->toJson();
$json  = json_encode($post);  // Models implement JsonSerializable
```

`toArray()` returns all column values with casts applied:

```php
[
    'id'           => 1,
    'title'        => 'Hello World',
    'published_at' => '2024-01-15 12:00:00',  // datetime cast → formatted string
    'view_count'   => 42,                      // int cast → int
    'is_featured'  => false,                   // bool cast → bool
    'created_at'   => '2024-01-15 09:00:00',
    'updated_at'   => '2024-01-15 12:00:00',
]
```

Relationship data is **not** included unless explicitly appended. Hidden attributes (passwords, tokens) are excluded from `toArray()` if declared in `$hidden`.

## Testing Models

Generate factories and tests from your model automatically:

```bash
php fw make:test Post            # Unit + feature tests from $fillable, casts, relations
php fw make:factory PostFactory  # Faker-based factory
```

See [testing.md](testing.md) for factory usage, `RefreshDatabase`, and architecture tests.

## Complete Example

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Fw\Model\Model;
use Fw\Model\Relations\BelongsTo;
use Fw\Model\Relations\HasMany;

class Post extends Model
{
    protected static ?string $table = 'posts';

    protected static array $fillable = [
        'title',
        'slug',
        'content',
        'user_id',
        'published_at',
    ];

    protected static array $casts = [
        'published_at' => 'datetime',
        'view_count'   => 'int',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }

    public static function published(): array
    {
        return static::whereNotNull('published_at')
            ->where('published_at', '<=', date('Y-m-d H:i:s'))
            ->orderBy('published_at', 'desc')
            ->get();
    }

    protected function beforeSave(): void
    {
        if (empty($this->slug)) {
            $this->slug = \Fw\Support\Str::slug($this->title);
        }
    }
}
```
