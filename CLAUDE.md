# Fw Framework - AI Assistant Rules

This document defines strict rules for AI assistants working with the Fw PHP framework.

---

## CRITICAL: Framework Usage Rules

### DO NOT MODIFY THE FRAMEWORK

The `src/` directory contains the framework core. **NEVER modify, improve, or refactor any file in `src/`**.

```
src/                    # READONLY - DO NOT MODIFY
├── Core/               # ❌ Never touch
├── Database/           # ❌ Never touch
├── Model/              # ❌ Never touch
├── Auth/               # ❌ Never touch
├── Cache/              # ❌ Never touch
├── Queue/              # ❌ Never touch
├── Middleware/         # ❌ Never touch
├── Security/           # ❌ Never touch
├── Console/            # ❌ Never touch
├── Async/              # ❌ Never touch
├── Validation/         # ❌ Never touch
├── Support/            # ❌ Never touch
├── Testing/            # ❌ Never touch
├── Events/             # ❌ Never touch
├── Lifecycle/          # ❌ Never touch
└── Types/              # ❌ Never touch
```

**If you think the framework has a bug or could be improved:**
1. DO NOT fix it yourself
2. Report it to the user
3. Let them decide whether to open an issue

### ONLY WORK IN APPLICATION DIRECTORIES

All application code goes in these directories:

```
app/                    # ✅ YOUR APPLICATION CODE
├── Controllers/        # ✅ HTTP request handlers
├── Models/             # ✅ Database models (extend Fw\Model\Model)
├── Views/              # ✅ PHP templates
│   └── layouts/        # ✅ Layout templates
├── Middleware/         # ✅ Custom middleware
├── Providers/          # ✅ Service providers
├── Commands/           # ✅ Custom CLI commands
├── Jobs/               # ✅ Queue jobs
└── Services/           # ✅ Business logic services

config/                 # ✅ CONFIGURATION FILES
├── app.php             # ✅ Application settings
├── database.php        # ✅ Database connections
├── routes.php          # ✅ Route definitions
├── middleware.php      # ✅ Middleware aliases & groups
└── providers.php       # ✅ Service provider registration

database/               # ✅ DATABASE FILES
├── migrations/         # ✅ Database migrations
├── seeders/            # ✅ Database seeders
└── factories/          # ✅ Model factories for testing

tests/                  # ✅ TEST FILES
├── Unit/               # ✅ Unit tests
├── Integration/        # ✅ Integration tests
└── Feature/            # ✅ Feature/E2E tests

public/                 # ✅ PUBLIC WEB ROOT
├── index.php           # ✅ Application entry point
├── css/                # ✅ Stylesheets
├── js/                 # ✅ JavaScript
└── images/             # ✅ Static images

storage/                # ✅ RUNTIME STORAGE (gitignored)
├── cache/              # Cache files
├── logs/               # Log files
├── queue/              # File-based queue storage
└── views/              # Compiled view cache
```

### FORBIDDEN ACTIONS

```
❌ NEVER modify files in src/
❌ NEVER "fix" or "improve" framework code
❌ NEVER add features to the framework
❌ NEVER refactor framework internals
❌ NEVER create files in src/
❌ NEVER suggest framework changes without explicit user request
```

### ALLOWED ACTIONS

```
✅ Create controllers in app/Controllers/
✅ Create models in app/Models/
✅ Create views in app/Views/
✅ Create middleware in app/Middleware/
✅ Create migrations in database/migrations/
✅ Create tests in tests/
✅ Modify config files in config/
✅ Use framework classes via imports
✅ Extend framework base classes
```

---

## Project Structure Convention

Every Fw project MUST follow this exact structure. Do not deviate.

### Required Files

```
project-root/
├── .env                    # Environment variables (from .env.example)
├── .env.example            # Environment template
├── composer.json           # Dependencies
├── fw                      # CLI entry point (executable)
├── public/
│   └── index.php           # Web entry point
├── config/
│   ├── app.php
│   ├── database.php
│   ├── routes.php
│   ├── middleware.php
│   └── providers.php
├── app/
│   ├── Controllers/
│   ├── Models/
│   └── Views/
│       └── layouts/
├── database/
│   └── migrations/
├── storage/
│   ├── cache/
│   ├── logs/
│   └── queue/
└── tests/
```

### Creating New Projects

When starting a new feature or project:

1. **Controllers** go in `app/Controllers/`
   ```php
   // app/Controllers/PostController.php
   namespace App\Controllers;

   use Fw\Core\Controller;
   use Fw\Core\Request;
   use Fw\Core\Response;

   class PostController extends Controller
   {
       public function index(Request $request): Response
       {
           $posts = Post::all();
           return $this->view('posts.index', ['posts' => $posts]);
       }
   }
   ```

2. **Models** go in `app/Models/`
   ```php
   // app/Models/Post.php
   namespace App\Models;

   use Fw\Model\Model;

   class Post extends Model
   {
       protected static ?string $table = 'posts';
       protected static array $fillable = ['title', 'content', 'user_id'];
   }
   ```

3. **Views** go in `app/Views/`
   ```php
   // app/Views/posts/index.php
   <?php $this->layout('main'); ?>

   <?php foreach ($posts as $post): ?>
       <h2><?= $e($post->title) ?></h2>
   <?php endforeach; ?>
   ```

4. **Routes** go in `config/routes.php`
   ```php
   // config/routes.php
   return function (Fw\Core\Router $router): void {
       $router->get('/posts', [App\Controllers\PostController::class, 'index']);
   };
   ```

5. **Migrations** go in `database/migrations/`
   ```bash
   php fw make:migration create_posts_table
   ```

---

## Consistency Requirements

All projects built with Fw MUST follow these patterns. This ensures every project works identically.

### Naming Conventions

| Type | Convention | Example |
|------|------------|---------|
| Controllers | PascalCase + `Controller` suffix | `PostController`, `UserController` |
| Models | PascalCase, singular | `Post`, `User`, `Comment` |
| Migrations | snake_case, descriptive | `create_posts_table`, `add_status_to_orders` |
| Views | snake_case, dot notation for folders | `posts.index`, `admin.users.edit` |
| Middleware | PascalCase + `Middleware` suffix | `AuthMiddleware`, `RateLimitMiddleware` |
| Jobs | PascalCase + action name | `SendWelcomeEmail`, `ProcessPayment` |
| Config keys | snake_case | `app.debug`, `database.default` |
| Routes | kebab-case URLs | `/user-profile`, `/blog-posts` |
| Tables | snake_case, plural | `users`, `blog_posts`, `order_items` |

### Controller Patterns

Always follow this structure:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;
use App\Models\Post;

class PostController extends Controller
{
    // List resources
    public function index(Request $request): Response
    {
        $posts = Post::orderBy('created_at', 'desc')->paginate(15, $request->get('page', 1));
        return $this->view('posts.index', ['posts' => $posts]);
    }

    // Show create form
    public function create(Request $request): Response
    {
        return $this->view('posts.create');
    }

    // Store new resource
    public function store(Request $request): Response
    {
        $validation = $this->validate($request, [
            'title' => 'required|min:3',
            'content' => 'required',
        ]);

        if ($validation->isErr()) {
            return $this->view('posts.create', ['errors' => $validation->getError()]);
        }

        $post = Post::create($validation->getValue());
        return $this->redirect('/posts/' . $post->id);
    }

    // Show single resource
    public function show(Request $request, string $id): Response
    {
        $post = Post::find((int) $id)->unwrapOr(null);

        if ($post === null) {
            return $this->notFound();
        }

        return $this->view('posts.show', ['post' => $post]);
    }

    // Show edit form
    public function edit(Request $request, string $id): Response
    {
        $post = Post::find((int) $id)->unwrapOr(null);

        if ($post === null) {
            return $this->notFound();
        }

        return $this->view('posts.edit', ['post' => $post]);
    }

    // Update resource
    public function update(Request $request, string $id): Response
    {
        $post = Post::find((int) $id)->unwrapOr(null);

        if ($post === null) {
            return $this->notFound();
        }

        $validation = $this->validate($request, [
            'title' => 'required|min:3',
            'content' => 'required',
        ]);

        if ($validation->isErr()) {
            return $this->view('posts.edit', ['post' => $post, 'errors' => $validation->getError()]);
        }

        $post->fill($validation->getValue())->save();
        return $this->redirect('/posts/' . $post->id);
    }

    // Delete resource
    public function destroy(Request $request, string $id): Response
    {
        $post = Post::find((int) $id)->unwrapOr(null);

        if ($post !== null) {
            $post->delete();
        }

        return $this->redirect('/posts');
    }
}
```

### Model Patterns

Always follow this structure:

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
        'content',
        'user_id',
        'published_at',
    ];

    protected static array $casts = [
        'published_at' => 'datetime',
        'is_featured' => 'bool',
        'view_count' => 'int',
    ];

    // Relationships
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    // Scopes (static query methods)
    public static function published(): array
    {
        return static::where('published_at', '<=', date('Y-m-d H:i:s'))
            ->orderBy('published_at', 'desc')
            ->get();
    }
}
```

### Route Patterns

Always follow this structure in `config/routes.php`:

```php
<?php

declare(strict_types=1);

use Fw\Core\Router;
use App\Controllers\HomeController;
use App\Controllers\PostController;
use App\Controllers\Admin\DashboardController;

return function (Router $router): void {
    // Public routes
    $router->get('/', [HomeController::class, 'index'], 'home');
    $router->get('/posts', [PostController::class, 'index'], 'posts.index');
    $router->get('/posts/{id}', [PostController::class, 'show'], 'posts.show');

    // Auth routes
    $router->group('/auth', function (Router $router) {
        $router->get('/login', [AuthController::class, 'showLogin'], 'login');
        $router->post('/login', [AuthController::class, 'login']);
        $router->post('/logout', [AuthController::class, 'logout'], 'logout');
    });

    // Protected routes
    $router->group('/dashboard', function (Router $router) {
        $router->get('/', [DashboardController::class, 'index'], 'dashboard');
        $router->get('/posts/create', [PostController::class, 'create'], 'posts.create');
        $router->post('/posts', [PostController::class, 'store'], 'posts.store');
    }, middleware: ['auth']);

    // API routes
    $router->group('/api', function (Router $router) {
        $router->get('/posts', [Api\PostController::class, 'index']);
        $router->get('/posts/{id}', [Api\PostController::class, 'show']);
    }, middleware: ['api', 'throttle']);

    // Admin routes
    $router->group('/admin', function (Router $router) {
        $router->get('/dashboard', [Admin\DashboardController::class, 'index']);
    }, middleware: ['auth', 'admin']);
};
```

### Migration Patterns

Always follow this structure:

```php
<?php

declare(strict_types=1);

use Fw\Database\Migration;
use Fw\Database\Schema;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('posts', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('content');
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->integer('view_count')->default(0);
            $table->timestamps();

            $table->index('published_at');
            $table->index(['user_id', 'published_at']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('posts');
    }
};
```

### View Patterns

Always use these conventions:

```php
<!-- app/Views/layouts/main.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e($title ?? 'Fw App') ?></title>
    <?= $csrf() ?>
</head>
<body>
    <main>
        <?= $yield('content') ?>
    </main>
</body>
</html>
```

```php
<!-- app/Views/posts/index.php -->
<?php $this->layout('main'); ?>
<?php $title = 'All Posts'; ?>

<?php $section('content'); ?>
    <h1>Posts</h1>

    <?php foreach ($posts['items'] as $post): ?>
        <article>
            <h2><a href="<?= $url('posts.show', ['id' => $post->id]) ?>"><?= $e($post->title) ?></a></h2>
            <p><?= $e($strLimit($post->content, 150)) ?></p>
            <time><?= $formatDate($post->created_at) ?></time>
        </article>
    <?php endforeach; ?>

    <!-- Pagination -->
    <?php if ($posts['last_page'] > 1): ?>
        <nav>
            <?php for ($i = 1; $i <= $posts['last_page']; $i++): ?>
                <a href="?page=<?= $i ?>" <?= $i === $posts['current_page'] ? 'class="active"' : '' ?>><?= $i ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php $endSection(); ?>
```

### View Helpers Reference

All view templates have access to these built-in helper functions and support classes.

#### Template Functions

| Helper | Signature | Description |
|--------|-----------|-------------|
| `$e()` | `fn(string $value): string` | Escape HTML (htmlspecialchars with ENT_QUOTES) |
| `$url()` | `fn(string $name, array $params = []): string` | Generate URL from named route |
| `$csrf()` | `fn(): string` | Output CSRF token as hidden form field |
| `$old()` | `fn(string $key, mixed $default = null): mixed` | Get old form input from session |
| `$section()` | `fn(string $name): void` | Start a named section |
| `$endSection()` | `fn(): void` | End the current section |
| `$yield()` | `fn(string $name, string $default = ''): string` | Output section content in layout |
| `$strLimit()` | `fn(string $value, int $limit = 100, string $end = '...'): string` | Truncate string |
| `$strSlug()` | `fn(string $value): string` | Convert to URL slug |
| `$strUpper()` | `fn(string $value): string` | Uppercase |
| `$strLower()` | `fn(string $value): string` | Lowercase |
| `$strTitle()` | `fn(string $value): string` | Title case |
| `$strExcerpt()` | `fn(string $text, string $phrase = '', int $radius = 100): ?string` | Extract excerpt around phrase |
| `$formatDate()` | `fn(?\DateTimeInterface $date, string $format = 'F j, Y'): string` | Format date |
| `$timeAgo()` | `fn(?\DateTimeInterface $date): string` | Human-readable time diff ("2 hours ago") |
| `$cache()` | `fn(string $key, int $ttl = 3600): bool` | Start fragment cache (true = cache miss) |
| `$endCache()` | `fn(): void` | End fragment cache |

#### Support Classes

Three utility classes are available as variables in all views:

**`$Str`** - String utilities (`Fw\Support\Str`)
```php
<?= $Str::slug('Hello World') ?>          <!-- hello-world -->
<?= $Str::limit('Long text...', 50) ?>    <!-- Long text... -->
<?= $Str::title('hello world') ?>         <!-- Hello World -->
<?= $Str::camel('foo_bar') ?>             <!-- fooBar -->
<?= $Str::kebab('fooBar') ?>              <!-- foo-bar -->
<?= $Str::snake('fooBar') ?>              <!-- foo_bar -->
<?= $Str::random(16) ?>                   <!-- random string -->
<?= $Str::uuid() ?>                       <!-- UUID v4 -->
<?= $Str::contains('hello', 'ell') ?>     <!-- true -->
<?= $Str::mask('secret@email.com', '*', 3) ?>

<!-- Fluent chaining with Str::of() -->
<?= $Str::of('hello world')->title()->slug()->value() ?>
```

Key methods: `after`, `afterLast`, `before`, `beforeLast`, `between`, `camel`, `contains`, `endsWith`, `startsWith`, `finish`, `headline`, `is`, `isJson`, `isUuid`, `kebab`, `length`, `limit`, `lower`, `upper`, `title`, `slug`, `snake`, `studly`, `substr`, `trim`, `words`, `wordCount`, `plural`, `singular`, `random`, `uuid`, `ulid`, `mask`, `replaceFirst`, `replaceLast`, `reverse`, `squish`, `wrap`, `of` (fluent)

**`$DateTime`** - DateTime utilities (`Fw\Support\DateTime`)
```php
<?php $now = $DateTime::now(); ?>
<?php $date = $DateTime::parse('2024-01-15'); ?>
<?php $ts = $DateTime::fromTimestamp(1700000000); ?>

<?= $date->format('F j, Y') ?>             <!-- January 15, 2024 -->
<?= $date->toDateString() ?>               <!-- 2024-01-15 -->
<?= $date->diffForHumans() ?>              <!-- 1 year ago -->
<?= $date->addDays(30)->format('Y-m-d') ?> <!-- 2024-02-14 -->
<?= $now->isWeekend() ? 'Weekend' : 'Weekday' ?>
```

Key methods: `now`, `today`, `yesterday`, `tomorrow`, `parse`, `fromTimestamp`, `create`, `format`, `toDateString`, `toTimeString`, `toDateTimeString`, `toIso8601`, `diffForHumans`, `diffInDays`, `diffInHours`, `addDays`, `addHours`, `addMonths`, `subDays`, `subHours`, `startOfDay`, `endOfDay`, `startOfMonth`, `endOfMonth`, `isToday`, `isPast`, `isFuture`, `isWeekend`, `isWeekday`, `isBefore`, `isAfter`, `isBetween`, `age`, `year`, `month`, `day`

**`$Arr`** - Array utilities (`Fw\Support\Arr`)
```php
<?php
$config = ['db' => ['host' => 'localhost', 'port' => 3306]];
$host = $Arr::get($config, 'db.host');              // 'localhost'
$names = $Arr::pluck($users, 'name');                // ['John', 'Jane']
$admins = $Arr::where($users, fn($u) => $u['role'] === 'admin');
$first = $Arr::first($items, fn($i) => $i > 10);
?>
```

Key methods: `get`, `set`, `has`, `forget` (dot notation), `pluck`, `only`, `except`, `first`, `last`, `flatten`, `dot`, `undot`, `where`, `whereNotNull`, `groupBy`, `keyBy`, `sortBy`, `sortByDesc`, `unique`, `wrap`, `collapse`, `random`, `shuffle`, `any`, `all`, `map`, `mapWithKeys`, `isAssoc`, `isList`

#### Reserved Variable Names

These names are reserved for helpers and cannot be used as view data keys:

`e`, `url`, `csrf`, `old`, `section`, `endSection`, `yield`, `strLimit`, `strSlug`, `strUpper`, `strLower`, `strTitle`, `strExcerpt`, `formatDate`, `timeAgo`, `Str`, `DateTime`, `Arr`, `path`, `data`, `this`, `cache`, `endCache`

### CLI Usage Patterns

Always use the `fw` CLI for generation:

```bash
# Generate a complete resource
php fw make:model Post -m                    # Model + migration
php fw make:controller PostController -r     # Resource controller

# Run migrations
php fw migrate

# Check routes
php fw routes:list

# Development server
php fw serve
```

---

## Architecture Overview

**PHP Version:** 8.4+ (uses property hooks, asymmetric visibility)

**Core Patterns:**
- **Fiber-based async** - `EventLoop` manages non-blocking I/O
- **Result/Option monads** - No null returns, explicit error handling
- **RequestContext** - Request-scoped state (NOT static singletons)
- **Immutable QueryBuilder** - Chain methods return `$this` (mutable for performance)

## Directory Structure

```
src/
├── Core/           # Application, Router, Request, Response, Container, Env
├── Console/        # CLI framework
│   ├── Application.php   # Command registry & runner
│   ├── Command.php       # Base command class
│   ├── Input.php         # Argument/option parser
│   ├── Output.php        # Colorized terminal output
│   └── Commands/         # Built-in commands
├── Database/       # Connection, QueryBuilder, Migrations
├── Model/          # ORM with Option<T> returns
├── Auth/           # Session-based + API token auth
├── Cache/          # File, APCu drivers
├── Queue/          # Job processing (File, Database drivers)
├── Middleware/     # Pipeline-based middleware
├── Security/       # Validator, Sanitizer, CSRF
├── Async/          # EventLoop, Fiber management
└── Types/          # Result<T,E>, Option<T>

app/
├── Controllers/    # Request handlers
├── Models/         # Application models
├── Views/          # PHP templates
└── Providers/      # Service providers

stubs/              # Code generation templates
├── model.stub
├── controller.stub
├── controller.resource.stub
├── migration.stub
├── migration.create.stub
└── middleware.stub
```

## Security Requirements

### NEVER Do These:
```php
// ❌ serialize/unserialize (RCE vulnerability)
$data = unserialize($input);

// ❌ Static user state (leaks between requests in worker mode)
private static ?User $user = null;

// ❌ Unvalidated SQL operators
->where('id', $userOperator, $value)

// ❌ String concatenation in SQL
"SELECT * FROM users WHERE id = " . $id

// ❌ Trust X-Forwarded-* headers without proxy config
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];

// ❌ Hardcoded SQL quotes (breaks MySQL)
$this->execute('DROP TABLE "users"');
```

### ALWAYS Do These:
```php
// ✅ Use JSON for serialization
$data = json_decode($input, true);

// ✅ Use RequestContext for request-scoped state
RequestContext::current()->set('user', $user);

// ✅ Whitelist operators
$allowedOps = ['=', '!=', '>', '<', '>=', '<=', 'LIKE'];

// ✅ Parameterized queries (including LIMIT/OFFSET)
->where('id', '=', $id)->limit($limit)

// ✅ Configure trusted proxies
Request::setTrustedProxies(['10.0.0.0/8']);

// ✅ Use quote() method for identifiers
$this->execute('DROP TABLE ' . $this->quote('users'));
```

## Type Patterns

### Result Type (for operations that can fail)
```php
public function save(): Result<static, DatabaseError>

// Usage
$result = $user->save();
if ($result->isOk()) {
    $user = $result->getValue();
} else {
    $error = $result->getError();
}

// Or fluent
$user->save()
    ->map(fn($u) => $this->sendEmail($u))
    ->mapErr(fn($e) => $this->logError($e));
```

### Option Type (for nullable values)
```php
public static function find(int $id): Option<static>

// Usage
$user = User::find($id);
if ($user->isSome()) {
    $user = $user->unwrap();
}

// Or with default
$user = User::find($id)->unwrapOr(new GuestUser());
```

## Database

### MySQL vs SQLite Differences
- MySQL uses backticks: `` `table` ``
- SQLite/PostgreSQL use double quotes: `"table"`
- Always use `$this->quote()` or `$db->quoteIdentifier()`

### Connection Management
```php
// Single request (traditional PHP-FPM)
$db = Connection::getInstance($config);

// Worker mode (FrankenPHP) - reset between requests
$db->resetRequestState();

// Or create fresh per request
$db = Connection::createFresh($config);
```

### Pagination
```php
$pagination = User::orderBy('created_at', 'desc')->paginate(15, $page);
// Returns: ['items' => [...], 'total' => 100, 'per_page' => 15, 'current_page' => 1, 'last_page' => 7]
```

## Common Mistakes & Fixes

| Mistake | Fix |
|---------|-----|
| `time()` for unique IDs | `uniqid('', true)` or `bin2hex(random_bytes(16))` |
| `array_first()` not found | Check `function_exists()` or use `reset()` |
| MySQL "identifier" error | Use backticks via `$this->quote()` |
| Foreign key constraint fails | Ensure `foreignId()` matches `id()` type (UNSIGNED) |
| Transaction has no effect | MySQL auto-commits DDL (CREATE TABLE) |
| Session leaks between requests | Use `RequestContext`, not static properties |

## Testing & Quality Assurance

### Running Tests

```bash
# All tests
php fw test
composer test

# Specific test suites
php fw test --testsuite=unit
php fw test --testsuite=architecture
composer test:unit

# With coverage
php fw test --coverage
composer test:coverage

# Filter specific tests
php fw test --filter=testEnvLoads

# Mutation testing (checks test quality)
composer test:mutation
```

### Full Validation Pipeline

```bash
# Run ALL checks (recommended before committing)
php fw validate:all
composer validate

# Or run individual checks
php fw validate:config      # Config schema validation
php fw validate:security    # Security vulnerability scan
composer lint               # Code style
composer analyse            # Static analysis
```

### Pre-commit Hook

Install the pre-commit hook for automatic checks:
```bash
cp .hooks/pre-commit .git/hooks/pre-commit
chmod +x .git/hooks/pre-commit
```

The hook runs on staged PHP files:
1. PHP syntax check
2. PHP-CS-Fixer (code style)
3. PHPStan (static analysis level 8)
4. Security checks (eval, unserialize, debug code)
5. Merge conflict markers

### CI Pipeline

GitHub Actions automatically runs on every push/PR:
- Static analysis (PHPStan)
- Code style (PHP-CS-Fixer)
- Security scan
- Unit/Integration tests with coverage
- Architecture tests (layer violations)
- Mutation testing (on main branch)

## Architecture Rules

The following rules are enforced by architecture tests:

| Rule | Enforced By |
|------|-------------|
| Controllers cannot access PDO/Connection directly | `LayerTest` |
| Models cannot import Controllers | `LayerTest` |
| Framework (src/) cannot depend on App (app/) | `LayerTest` |
| Views cannot contain class definitions | `LayerTest` |
| No circular dependencies between namespaces | `LayerTest` |
| No unserialize without allowed_classes | `SecurityPatternTest` |
| No eval/create_function | `SecurityPatternTest` |
| No hardcoded credentials | `SecurityPatternTest` |
| No debug functions in production code | `SecurityPatternTest` |

### Writing Tests

```php
// tests/Unit/YourTest.php
namespace Fw\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class YourTest extends TestCase
{
    #[Test]
    public function itDoesWhatYouExpect(): void
    {
        // Arrange
        $input = 'test';

        // Act
        $result = doSomething($input);

        // Assert
        $this->assertSame('expected', $result);
    }
}
```

## CLI Commands

The `fw` CLI provides a unified interface for all development tasks:

```bash
php fw                       # Show all available commands
php fw help <command>        # Show help for a specific command
```

### Code Generators

```bash
# Models
php fw make:model Post              # Create model
php fw make:model Post -m           # Create model + migration

# Controllers
php fw make:controller PostController           # Basic controller
php fw make:controller PostController -r        # Resource controller (CRUD)
php fw make:controller Api/PostController -r    # Namespaced controller

# Migrations
php fw make:migration create_posts_table        # Auto-detects table name
php fw make:migration add_status_to_posts       # Generic migration

# Middleware
php fw make:middleware RateLimitMiddleware      # Creates in src/Middleware/
```

### Database Migrations

```bash
php fw migrate               # Run pending migrations
php fw migrate:status        # Show migration status table
php fw migrate:rollback      # Rollback last batch
php fw migrate:rollback --step=3  # Rollback 3 migrations
php fw migrate:fresh         # Drop all tables and re-run migrations
php fw migrate:fresh --seed  # Fresh migrate + seed
```

### Development Utilities

```bash
php fw serve                 # Start dev server (localhost:8000)
php fw serve --port=8080     # Custom port
php fw routes:list           # List all registered routes
php fw routes:list --method=GET  # Filter by HTTP method
php fw cache:clear           # Clear all caches
php fw cache:clear --views   # Clear only view cache
```

### Production Optimization

```bash
# Optimize everything for deployment (RECOMMENDED)
php fw optimize              # Cache routes, config; clear stale caches

# Individual caching commands
php fw config:cache          # Cache configuration (single file)
php fw config:clear          # Clear config cache
php fw route:cache           # Cache routes (bypass route registration)
php fw route:clear           # Clear route cache

# Clear all optimizations (for development)
php fw optimize:clear        # Clear all caches at once
```

**Deployment workflow:**
```bash
git pull
composer install --no-dev --optimize-autoloader
php fw migrate
php fw optimize
```

### Security & Validation

```bash
php fw validate:security     # Scan for vulnerabilities
php fw validate:security --path=app/Controllers  # Scan specific path
```

The security scanner detects:
- `unserialize()` without `allowed_classes` (RCE)
- `eval()`, `create_function()`, `preg_replace /e` (code injection)
- SQL injection patterns
- XSS vulnerabilities
- Command injection
- Hardcoded credentials
- Debug code (`var_dump`, `dd`, etc.)

### Legacy Scripts (deprecated)

These still work but prefer the `fw` CLI:
```bash
php migrate.php migrate      # Use: php fw migrate
php migrate.php fresh        # Use: php fw migrate:fresh
php security-scan.php        # Use: php fw validate:security
```

## Environment Variables

Required for MySQL:
```env
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=myapp
DB_USERNAME=user
DB_PASSWORD=secret
DB_PERSISTENT=false  # true for connection pooling
```

## Performance Notes

- **Reads:** 5,500+ req/sec (SQLite), 10,000+ req/sec (MySQL)
- **Writes:** 650 req/sec (SQLite - single writer), 3,500+ req/sec (MySQL)
- **Worker mode:** FrankenPHP keeps app bootstrapped for 5-10x throughput
- **Persistent connections:** Enable `DB_PERSISTENT=true` for connection reuse

### Production Checklist

```bash
# 1. Run optimization (caches routes, config)
php fw optimize

# 2. Enable OPcache in php.ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0   # IMPORTANT: disable in production
opcache.revalidate_freq=0

# 3. Verify caches exist
ls -la storage/cache/
# Should see: config.php, routes.php
```

### View Caching & Streaming

For high-traffic pages, use view caching and streaming to reduce response time:

```php
// In controller - cache rendered view for 1 hour
public function about(Request $request): Response
{
    return $this->cachedView('pages.about', [], 3600);
}

// Stream large pages directly (lower memory, faster TTFB)
public function report(Request $request): StreamedResponse
{
    return $this->streamedView('reports.large', ['data' => $data]);
}
```

Enable view caching in bootstrap:
```php
// In provider or bootstrap
$view->enableCache(BASE_PATH . '/storage/cache/views');
```

Fragment caching in views:
```php
<?php if ($cache('sidebar', 3600)): ?>
    <!-- expensive sidebar rendered only on cache miss -->
<?php $endCache(); endif; ?>
```

Invalidate caches when content changes:
```php
$view->invalidate('pages.about');           // Full page
$view->invalidateFragment('sidebar');        // Fragment
$view->clearCache();                         // All views
