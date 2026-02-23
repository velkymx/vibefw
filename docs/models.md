# Models

Models represent database tables and handle data persistence. They use the Active Record pattern and live in `app/Models/`.

## Creating a Model

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
        'views' => 'int',
        'metadata' => 'array',
    ];
}
```

## Model Configuration

### Table Name

```php
protected static ?string $table = 'posts';
```

If not specified, the table name is derived from the class name (e.g., `Post` → `posts`).

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

### Timestamps

```php
protected static bool $timestamps = true;  // Manages created_at, updated_at
```

### Fillable Fields

Mass-assignable attributes:

```php
protected static array $fillable = ['title', 'content', 'user_id'];
```

### Type Casting

```php
protected static array $casts = [
    'published_at' => 'datetime',
    'is_active' => 'bool',
    'views' => 'int',
    'price' => 'float',
    'metadata' => 'array',
    'settings' => 'json',
];
```

Available cast types: `int`, `float`, `bool`, `string`, `array`, `json`, `datetime`

## Querying Models

### Basic Retrieval

```php
// Get all records
$posts = Post::all();  // Returns Collection

// Find by ID (returns Option, not null!)
Post::find($id)->match(
    some: fn($post) => $post->title,
    none: fn() => 'Not found'
);

// Find or create
$post = Post::firstOrCreate(
    ['email' => $email],           // Search criteria
    ['name' => $name]              // Additional data if creating
);
```

### Query Builder

```php
// Where clauses
$posts = Post::where('status', 'published')->get();
$posts = Post::where('views', '>', 100)->get();
$posts = Post::where('status', 'published')
    ->where('user_id', $userId)
    ->get();

// Or where
$posts = Post::where('status', 'published')
    ->orWhere('featured', true)
    ->get();

// Where in
$posts = Post::whereIn('id', [1, 2, 3])->get();

// Where null / not null
$posts = Post::whereNull('deleted_at')->get();
$posts = Post::whereNotNull('published_at')->get();

// Ordering
$posts = Post::orderBy('created_at', 'desc')->get();
$posts = Post::latest()->get();  // Order by created_at desc
$posts = Post::oldest()->get();  // Order by created_at asc

// Limiting
$posts = Post::limit(10)->get();
$posts = Post::limit(10)->offset(20)->get();

// First record
$post = Post::where('slug', $slug)->first();  // Returns Option

// Count
$count = Post::where('status', 'published')->count();
```

### Scopes

Define reusable query constraints:

```php
class Post extends Model
{
    // Static scope method
    public static function wherePublished(): static
    {
        return static::whereNotNull('published_at');
    }

    public static function byAuthor(int $userId): static
    {
        return static::where('user_id', $userId);
    }
}

// Usage
$posts = Post::wherePublished()->byAuthor($userId)->get();
```

## Creating & Updating

### Create

```php
// Create and save
$post = Post::create([
    'title' => 'My Post',
    'content' => 'Content here...',
    'user_id' => $userId,
]);

// Or build and save separately
$post = new Post();
$post->title = 'My Post';
$post->content = 'Content here...';
$post->save();
```

### Update

```php
// Find and update
Post::find($id)->map(function($post) {
    $post->update(['title' => 'New Title']);
});

// Or update attributes directly
$post->title = 'New Title';
$post->save();

// Mass update
Post::where('status', 'draft')->update(['status' => 'archived']);
```

### Delete

```php
// Delete single record
$post->delete();

// Mass delete
Post::where('created_at', '<', $date)->delete();
```

## Relationships

### Belongs To

```php
class Post extends Model
{
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

// Usage
$post->author()->get();  // Returns Option<User>
```

### Has Many

```php
class User extends Model
{
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}

// Usage
$user->posts()->get();  // Returns Collection
```

### Has One

```php
class User extends Model
{
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class, 'user_id');
    }
}
```

### Eager Loading

```php
// Load relationship with query
$posts = Post::with('author')->get();

// Multiple relationships
$posts = Post::with(['author', 'comments'])->get();

// Access without additional queries
foreach ($posts as $post) {
    echo $post->author->name;  // No N+1 query
}
```

## Accessors & Mutators

### Custom Getters

```php
class User extends Model
{
    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }
}

// Usage
echo $user->full_name;
```

### Custom Setters

```php
class User extends Model
{
    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = password_hash($value, PASSWORD_DEFAULT);
    }
}

// Usage
$user->password = 'plain-text';  // Automatically hashed
```

## Model Events

Override these methods for lifecycle hooks:

```php
class Post extends Model
{
    protected function beforeSave(): void
    {
        $this->slug = Str::slug($this->title);
    }

    protected function afterSave(): void
    {
        Cache::forget('posts.all');
    }

    protected function beforeDelete(): void
    {
        // Cleanup before deletion
    }
}
```

## Working with Dates

```php
// Cast to DateTime
protected static array $casts = [
    'published_at' => 'datetime',
];

// Usage
$post->published_at;                    // DateTimeImmutable
$post->published_at->format('Y-m-d');   // 2024-01-15
```

## Serialization

Models implement `\JsonSerializable`, so they serialize correctly in all contexts.

### To Array

```php
$array = $post->toArray();
```

Includes all attributes (with type casting applied for storage) and any loaded relationships.

### To JSON

```php
// Explicit
$json = $post->toJson();

// Via json_encode — works correctly because Model implements JsonSerializable
$json = json_encode($post);

// Works transparently in nested structures too
$json = json_encode(['post' => $post, 'meta' => $meta]);

// Collections are also JsonSerializable
$json = json_encode(Post::all());
```

`toJson()` and `json_encode()` produce identical output. Both call `toArray()` internally, which includes loaded relations.

## Example: Complete Model

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
        'views' => 'int',
    ];

    // Relationships
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }

    // Scopes
    public static function wherePublished(): static
    {
        return static::whereNotNull('published_at')
            ->where('published_at', '<=', date('Y-m-d H:i:s'));
    }

    public static function whereDraft(): static
    {
        return static::whereNull('published_at');
    }

    // Mutators
    protected function beforeSave(): void
    {
        if (empty($this->slug)) {
            $this->slug = \Fw\Support\Str::slug($this->title);
        }
    }

    // Business Logic
    public function publish(): void
    {
        $this->update(['published_at' => date('Y-m-d H:i:s')]);
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}
```
