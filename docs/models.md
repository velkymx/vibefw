# Models

Models represent database tables using the Active Record pattern. They live in `app/Models/`.

## Creating a Model

```bash
php fw make:model Post -m    # Model + migration
```

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

If not specified, derived from class name (`Post` → `posts`).

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

Every model **must** declare `$fillable`. `$guarded` does not exist. Strict mode is permanently on.

```php
protected static array $fillable = ['title', 'content', 'user_id'];
```

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

// Find by ID — returns Option, not null
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

### Belongs To

```php
use Fw\Model\Relations\BelongsTo;

public function author(): BelongsTo
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

```php
$posts = Post::with('author')->get();
$posts = Post::with(['author', 'comments'])->get();

foreach ($posts as $post) {
    echo $post->author->name;  // No N+1 query
}
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
$json = $post->toJson();
$json = json_encode($post);  // Models implement JsonSerializable
```

## Inspection

```bash
php fw model:inspect Post
# Shows: table, columns, fillable, casts, relations, row count, indexes
```

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
