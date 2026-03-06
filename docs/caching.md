# Caching

VibeFW provides a flexible caching system with multiple drivers for improved performance.

## Configuration

Configure caching via environment variables:

```env
CACHE_DRIVER=file
```

Available drivers: `file`, `apcu`, `memory`

## Basic Usage

### Getting the Cache

```php
// In controllers (via getContainer())
$cache = $this->app->getContainer()->get(CacheInterface::class);

// Or inject in services
public function __construct(
    private CacheInterface $cache,
) {}
```

### Storing Items

```php
// Store for default TTL (1 hour)
$cache->set('key', $value);

// Store for specific time (seconds)
$cache->set('key', $value, 3600);  // 1 hour
$cache->set('key', $value, 86400); // 24 hours
```

### Retrieving Items

```php
// Get value (null if not found)
$value = $cache->get('key');

// Get with default
$value = $cache->get('key', 'default');
```

### Checking Existence

```php
if ($cache->has('key')) {
    // Key exists and not expired
}
```

### Removing Items

```php
// Remove single item
$cache->delete('key');

// Clear all cache
$cache->clear();
```

## Remember Pattern

Store expensive operations and retrieve from cache:

```php
$posts = $cache->remember('popular-posts', function () {
    return Post::where('views', '>', 1000)
        ->orderBy('views', 'desc')
        ->limit(10)
        ->get();
}, 3600);  // Cache for 1 hour
```

## Multiple Operations

```php
// Get multiple keys
$values = $cache->getMany(['key1', 'key2', 'key3']);
// ['key1' => 'value1', 'key2' => 'value2', 'key3' => null]

// Set multiple keys
$cache->setMany([
    'key1' => 'value1',
    'key2' => 'value2',
], 3600);
```

## Page Caching

### Guest Page Cache

FW automatically caches pages for unauthenticated users:

```php
// config/middleware.php
'global' => [
    GuestPageCacheMiddleware::class,  // Caches pages for guests
],
```

This middleware:
- Only caches GET requests
- Skips users with session cookies
- Caches successful (200) responses

### Opt-in Page Cache

For specific routes that are safe to cache:

```php
$router->get('/api/products', [ProductController::class, 'list'])
    ->middleware('page_cache:300');  // Cache for 5 minutes
```

**Warning:** Never use on pages with:
- CSRF tokens
- User-specific content
- Session data

## Cache Tags (File Driver)

Organize cache by tags for selective clearing:

```php
// Store with tag
$cache->tags(['posts'])->set('post:1', $post);
$cache->tags(['posts', 'user:1'])->set('user:1:posts', $userPosts);

// Clear by tag
$cache->tags(['posts'])->flush();  // Clears all posts cache
```

## Cache Drivers

### File Cache

Default driver. Stores cache as serialized files.

```php
// Created automatically at storage/cache/
new FileCache(BASE_PATH . '/storage/cache', 3600);
```

**Pros:** Works everywhere, no dependencies
**Cons:** Slower than memory-based caches

### APCu Cache

In-memory cache using PHP's APCu extension.

```env
CACHE_DRIVER=apcu
```

**Pros:** Very fast, shared across requests
**Cons:** Requires APCu extension, limited memory

### Memory Cache

Per-request memory cache. Useful for repeated lookups within a request.

```php
new MemoryCache();
```

**Pros:** Fastest possible
**Cons:** Not shared between requests

### Tiered Cache

Combine multiple drivers for L1/L2 caching:

```php
// L1: Fast memory cache
// L2: Persistent file cache
$cache = new TieredCache(
    new MemoryCache(),      // L1
    new FileCache($path)    // L2
);
```

## Caching Patterns

### Query Caching

```php
class PostController extends Controller
{
    public function index(Request $request): Response
    {
        $cache = $this->app->getContainer()->get(CacheInterface::class);

        $posts = $cache->remember('posts:published', function () {
            return Post::wherePublished()
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get();
        }, 300);  // 5 minutes

        return $this->view('posts.index', compact('posts'));
    }
}
```

### Cache Invalidation

```php
class PostController extends Controller
{
    public function store(Request $request): Response
    {
        // ... create post ...

        // Invalidate cache
        $cache = $this->app->getContainer()->get(CacheInterface::class);
        $cache->delete('posts:published');

        return $this->redirect('/posts');
    }
}
```

### User-Specific Caching

```php
$userId = $this->user()->unwrap()->id;
$cacheKey = "user:{$userId}:dashboard";

$data = $cache->remember($cacheKey, function () use ($userId) {
    return [
        'posts' => Post::where('user_id', $userId)->count(),
        'comments' => Comment::where('user_id', $userId)->count(),
    ];
}, 600);
```

### API Response Caching

```php
class ApiController extends Controller
{
    public function products(Request $request): Response
    {
        $cache = $this->app->getContainer()->get(CacheInterface::class);
        $cacheKey = 'api:products:' . md5($request->uri . serialize($request->all()));

        $data = $cache->remember($cacheKey, function () {
            return Product::all()->toArray();
        }, 60);

        return $this->json($data);
    }
}
```

## Cache Headers

Set HTTP cache headers for browser caching:

```php
public function show(Request $request, string $id): Response
{
    return Post::find((int) $id)->match(
        some: function ($post) {
            return (new Response($this->app->view->render('posts.show', compact('post'))))
                ->cache(3600, public: true);  // Cache for 1 hour
        },
        none: fn() => $this->notFound()
    );
}
```

## Garbage Collection

Clean expired cache entries:

```php
// For FileCache
$cache->gc();  // Removes expired files
```

Run periodically via cron:

```bash
# Clear expired cache daily
0 3 * * * php /path/to/project/gc-cache.php
```

## Cache Warming

Pre-populate cache on deployment:

```php
// scripts/warm-cache.php
<?php

require __DIR__ . '/vendor/autoload.php';
define('BASE_PATH', __DIR__);

$app = Application::getInstance();
$cache = $app->getContainer()->get(CacheInterface::class);

// Warm popular queries
$cache->set('posts:published', Post::wherePublished()->get(), 3600);
$cache->set('categories:all', Category::all(), 86400);

echo "Cache warmed successfully.\n";
```

## Best Practices

1. **Use meaningful keys** - `user:1:posts` not `key1`
2. **Set appropriate TTLs** - Balance freshness vs performance
3. **Invalidate on writes** - Clear cache when data changes
4. **Don't cache sensitive data** - Session data, tokens, etc.
5. **Use cache tags** - For organized invalidation
6. **Monitor hit rates** - Ensure cache is effective
7. **Warm cache on deploy** - Pre-populate common queries

## Debugging

Check cache status via headers:

```bash
curl -I http://localhost:8080/
# X-Cache: HIT (served from cache)
# X-Cache: MISS (not cached, generated fresh)
# X-Cache: SKIP-SESSION (user has session, not cached)
```
