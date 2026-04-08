# VibeFW v3.0 — The Convention Machine

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.5-8892BF.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-passing-brightgreen.svg)]()

A PHP 8.5+ framework designed for AI-assisted development. One way to do each thing. No alternatives, no ambiguity.

**The problem:** AI coders don't choose patterns deliberately — they use whatever their training suggests. Multiple valid paths = inconsistent, fragile codebases.

**The solution:** VibeFW v3 removes every alternative. There is one way to validate input, one way to handle errors, one way to define routes. The old patterns are gone, not deprecated. An AI that follows the CLI prompts produces the same quality code regardless of model capability.

---

## Quick Start

```bash
composer create-project velkymx/vibefw my-app
cd my-app
php fw serve
```

Composer automatically creates `.env`, sets up storage directories, creates the SQLite database, and runs migrations.

### Build a Feature in 60 Seconds

```bash
# 1. Define the schema
php fw make:schema Post

# 2. Edit app/Schemas/Post.json — add your fields

# 3. Generate everything
php fw make:resource --schema=app/Schemas/Post.json

# 4. Run migrations
php fw migrate

# 5. Verify
php fw check
```

That generates: Model, Migration, Controller, FormRequests, Views, and test factory — all wired together with correct validation rules, `$fillable`, `$casts`, and route entries.

---

## Why "Convention Machine"?

VibeFW v3 is built for a world where AI writes most of the code. The framework enforces correctness at the type level so that mistakes are compile errors, not runtime bugs.

| Principle | How It Works |
|-----------|-------------|
| **Invalid states are unrepresentable** | Typed rules, exhaustive `match()`, no unsafe shortcuts |
| **CLI-first workflow** | Generators produce correct code; AI fills in the blanks |
| **Prescriptive errors** | Every error includes the exact command to fix it |
| **Works with weak models** | Gemma 3 running `make:*` commands produces the same quality as GPT-4 |

### One Way To Do Each Thing

| Concern | The Way |
|---------|---------|
| Validate input | `FormRequest` with typed rule objects |
| Handle errors | `Result<T,E>` with `match(ok:, err:)` |
| Handle missing data | `Option<T>` with `match(some:, none:)` |
| Mass assignment | `$fillable` array on every Model |
| Define routes | `[Controller::class, 'method']` with typed middleware |
| Return from controllers | `Response` object — always |
| Inject dependencies | Constructor injection |
| Define a feature | `make:schema` → `make:resource --schema=` |
| Add a field later | `php fw add:field Post slug string` |
| Link models | `php fw make:link Post Comment --hasMany` |

If it's not in the table, it doesn't exist.

---

## Agentic Development Workflow

VibeFW v3 is designed so that any AI — from Claude to a local Gemma 3 — can build features using only CLI commands and structured output. No guessing, no hallucinating APIs.

### The Loop

```
1. php fw ai:next          → "What should I do next?"
2. php fw make:schema Post → Generate JSON schema template
3. Edit the schema JSON    → Add fields, types, modifiers
4. php fw make:resource    → Generate all files from schema
5. php fw check            → Validate everything
6. php fw fix              → Auto-correct violations
7. Repeat from step 1
```

### AI Context Commands

These commands exist specifically so AI models can understand your project without reading every file.

**`php fw ai:map`** — Generates `ai-app-map.md`: a structured map of routes → controllers → models → requests → relationships. Auto-regenerated after every `make:*` command. Committed to git.

```bash
php fw ai:map
# Writes ai-app-map.md with the full project graph
```

**`php fw ai:context <topic>`** — Dumps all files for a feature into one output. Give it a topic name and it finds the controller, model, form requests, migration, and views.

```bash
php fw ai:context posts
# Outputs: PostController, Post model, StorePostRequest, UpdatePostRequest,
#          create_posts_table migration, all post views

php fw ai:context posts --compact   # Strip comments
php fw ai:context posts --json      # Machine-readable output
```

**`php fw ai:next`** — Suggests the logical next step with the exact command. Weak models don't plan — this plans for them.

```bash
php fw ai:next
# → "You have a Post model but no tests. Run: php fw make:test Post"
```

### Inspection Commands

These give AI models structured data about the project state without needing to parse files.

```bash
# Model details: table, columns, fillable, casts, relations, row count
php fw model:inspect Post

# Routes for a feature: method, path, controller, middleware
php fw route:for post

# Database state: connection, tables, pending migrations
php fw db:status

# Find tests for a feature
php fw test:for post

# Parse an error and get fix instructions
php fw error:explain "Mass assignment violation on Post"
```

### Validation & Auto-Fix

```bash
# Single entry point: conventions + architecture tests + PHPStan
php fw check

# Auto-correct common violations
php fw fix
```

`check` runs all convention checks, architecture tests, and static analysis in one command. Every violation includes the exact `fix` command or manual step to resolve it.

---

## Schema-Driven Generators

The primary workflow for building features. A JSON schema defines the resource, the framework validates it, then generates everything.

### Step 1: Create the Schema

```bash
php fw make:schema Post
```

Generates `app/Schemas/Post.json`:

```json
{
  "$schema": "fw://resource-schema",
  "model": "Post",
  "table": "posts",
  "api": false,
  "fields": {
    "title":   { "type": "string", "length": 255, "required": true },
    "content": { "type": "text", "required": true }
  }
}
```

### Step 2: Customize the Schema

Add fields, set types, configure modifiers:

```json
{
  "$schema": "fw://resource-schema",
  "model": "Post",
  "table": "posts",
  "api": false,
  "fields": {
    "title":        { "type": "string",    "length": 255, "required": true },
    "content":      { "type": "text",      "required": true },
    "user_id":      { "type": "foreignId", "constrained": true, "onDelete": "cascade" },
    "published_at": { "type": "timestamp", "nullable": true },
    "is_featured":  { "type": "boolean",   "default": false },
    "view_count":   { "type": "integer",   "default": 0 }
  }
}
```

**Field types:** `string`, `text`, `integer`, `boolean`, `timestamp`, `date`, `decimal`, `foreignId`, `json`

**Modifiers:** `required`, `nullable`, `unique`, `default`, `length`, `constrained`, `onDelete`, `index`

### Step 3: Generate Everything

```bash
php fw make:resource --schema=app/Schemas/Post.json
```

Generates with correct types, validation rules, and wiring:
- `app/Models/Post.php` — with `$fillable` and `$casts`
- `database/migrations/..._create_posts_table.php` — with correct column types and indexes
- `app/Controllers/PostController.php` — with FormRequest usage
- `app/Requests/StorePostRequest.php` — with typed validation rules
- `app/Requests/UpdatePostRequest.php` — with typed validation rules
- `app/Views/posts/*.php` — index, create, edit, show
- Route entries printed to console

Schema validation errors are prescriptive:

```
Validating schema...
  ✗ Field "titl": unknown type "strng".
    Valid types: string, text, integer, boolean, timestamp, date, decimal, foreignId, json
    Did you mean: "type": "string"?
Fix app/Schemas/Post.json and re-run.
```

### Adding Fields to Existing Resources

```bash
php fw add:field Post category_id foreignId --constrained
```

Updates all files atomically: creates migration, adds to `$fillable` and `$casts`, adds typed validation rule to FormRequest, updates schema JSON, regenerates `ai-app-map.md`.

### Wiring Relationships

```bash
php fw make:link Post Comment --hasMany
php fw make:link Post Category --manyToMany
```

Generates the migration (foreign key or pivot table), updates both models with relationship methods, updates `$fillable`, and regenerates `ai:map`.

### Generating Tests

```bash
php fw make:test Post
```

Generates feature and unit tests from the existing model's `$fillable`, relations, and casts. Runnable immediately.

---

## Typed Validation Rules

String pipe rules are gone. Every validation rule is a typed PHP object.

```php
use Fw\Validation\FormRequest;
use Fw\Validation\Rules\{Required, MinLength, MaxLength, Email, InEnum, Unique};

final class StorePostRequest extends FormRequest
{
    public string $title;
    public string $content;
    public string $status;

    public function rules(): array
    {
        return [
            'title'   => [new Required, new MinLength(3), new MaxLength(255)],
            'content' => [new Required],
            'status'  => [new Required, new InEnum(PostStatus::class)],
        ];
    }
}
```

Why this matters: a typo in `'required|min:3'` is a silent bug. A typo in `new Reqiured` is a fatal error. AI models catch fatals; they don't catch silent bugs.

**Available rules:** `Required`, `MinLength`, `MaxLength`, `Email`, `Url`, `In`, `InEnum`, `Regex`, `Between`, `Unique`, `Exists`, `Confirmed`

### Using FormRequests in Controllers

```php
public function store(Request $request): Response
{
    try {
        $data = StorePostRequest::fromRequest($request);
    } catch (ValidationException $e) {
        return $this->view('posts.create', ['errors' => $e->errors]);
    }

    $post = Post::create($data->toArray());
    return $this->redirect('/posts/' . $post->id);
}
```

---

## Result & Option Types

No `unwrap()`. No `getValue()`. You handle both cases or you don't compile.

### Result<T, E> — For Operations That Can Fail

```php
$post->save()->match(
    ok:  fn($p) => $this->redirect('/posts/' . $p->id),
    err: fn($e) => $this->view('posts.create', ['error' => $e->message]),
);
```

Chain operations:

```php
$user->save()
    ->map(fn($u) => $this->sendEmail($u))
    ->mapErr(fn($e) => $this->logError($e));
```

### Option<T> — For Values That Might Be Missing

```php
User::find($id)->match(
    some: fn($user) => $this->view('users.show', ['user' => $user]),
    none: fn()      => $this->notFound(),
);
```

With a default:

```php
$user = User::find($id)->unwrapOr(new GuestUser());
```

`#[\NoDiscard]` is applied to all methods returning `Result` or `Option` — PHP warns if you ignore the return value.

---

## Controller Pattern

Every controller method returns `Response`. No exceptions.

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;
use App\Models\Post;
use App\Requests\StorePostRequest;
use App\Requests\UpdatePostRequest;
use Fw\Validation\ValidationException;

class PostController extends Controller
{
    public function index(Request $request): Response
    {
        $posts = Post::orderBy('created_at', 'desc')->paginate(15, $request->get('page', 1));
        return $this->view('posts.index', ['posts' => $posts]);
    }

    public function create(Request $request): Response
    {
        return $this->view('posts.create');
    }

    public function store(Request $request): Response
    {
        try {
            $data = StorePostRequest::fromRequest($request);
        } catch (ValidationException $e) {
            return $this->view('posts.create', ['errors' => $e->errors]);
        }

        $post = Post::create($data->toArray());
        return $this->redirect('/posts/' . $post->id);
    }

    public function show(Request $request, string $id): Response
    {
        return Post::find((int) $id)->match(
            some: fn($post) => $this->view('posts.show', ['post' => $post]),
            none: fn() => $this->notFound(),
        );
    }

    public function update(Request $request, string $id): Response
    {
        return Post::find((int) $id)->match(
            some: function ($post) use ($request) {
                try {
                    $data = UpdatePostRequest::fromRequest($request);
                } catch (ValidationException $e) {
                    return $this->view('posts.edit', ['post' => $post, 'errors' => $e->errors]);
                }

                $post->fill($data->toArray())->save();
                return $this->redirect('/posts/' . $post->id);
            },
            none: fn() => $this->notFound(),
        );
    }

    public function destroy(Request $request, string $id): Response
    {
        Post::find((int) $id)->match(
            some: fn($post) => $post->delete(),
            none: fn() => null,
        );

        return $this->redirect('/posts');
    }
}
```

---

## Model Pattern

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
        'is_featured'  => 'bool',
        'view_count'   => 'int',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public static function published(): array
    {
        return static::where('published_at', '<=', date('Y-m-d H:i:s'))
            ->orderBy('published_at', 'desc')
            ->get();
    }
}
```

`$guarded` does not exist. `$fillable` is the only mass assignment mechanism. Strict mode is permanently on.

---

## Routing

No closures. No string middleware. Typed everything.

```php
<?php

declare(strict_types=1);

use Fw\Core\Router;
use App\Controllers\PostController;
use App\Controllers\AuthController;
use App\Controllers\Admin\DashboardController;
use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;

return function (Router $router): void {
    // Public
    $router->get('/posts', [PostController::class, 'index'], 'posts.index');
    $router->get('/posts/{id}', [PostController::class, 'show'], 'posts.show');

    // Auth
    $router->get('/login', [AuthController::class, 'showLogin'], 'login');
    $router->post('/login', [AuthController::class, 'login']);
    $router->post('/logout', [AuthController::class, 'logout'], 'logout');

    // Protected — typed middleware
    $router->with(AuthMiddleware::class, function (Router $r) {
        $r->get('/dashboard', [DashboardController::class, 'index'], 'dashboard');
        $r->get('/posts/create', [PostController::class, 'create'], 'posts.create');
        $r->post('/posts', [PostController::class, 'store'], 'posts.store');

        // Admin — nested typed middleware
        $r->with(AdminMiddleware::class, function (Router $r) {
            $r->get('/admin', [DashboardController::class, 'admin'], 'admin');
        });
    });
};
```

Closures as route handlers throw `InvalidArgumentException` at registration time.

---

## Prescriptive Errors

Every framework exception includes `getFixCommand()` — the exact CLI command to resolve it.

```php
// MassAssignmentException
"Mass assignment violation: 'status' is not in Post::$fillable.
Fix: Add 'status' to the $fillable array in app/Models/Post.php
Or run: php fw fix"

// ModelNotFoundException
"Post not found with id 42.
Fix: Use Post::find($id)->match(some: ..., none: ...) to handle missing models."

// ForbiddenException
"Access denied: missing 'admin' role.
Fix: Add role check in your middleware or controller."
```

The `error:explain` command parses any error message:

```bash
php fw error:explain "Class 'App\Models\Psot' not found"
# → Likely a typo. Did you mean App\Models\Post?
#   Run: php fw model:inspect Post

php fw error:explain "Call to undefined method unwrap()"
# → unwrap() was removed in v3. Use match(some:, none:) instead.
#   See: UPGRADE.md
```

---

## CLI Reference

### Code Generators

```bash
php fw make:schema Post                              # JSON schema template
php fw make:resource --schema=app/Schemas/Post.json  # Full CRUD from schema
php fw add:field Post slug string --unique            # Add field to existing resource
php fw make:link Post Comment --hasMany               # Wire relationship
php fw make:test Post                                 # Generate tests from model
php fw make:model Post -m                             # Model + migration
php fw make:controller PostController -r              # Resource controller
php fw make:migration create_posts_table              # Migration
php fw make:middleware RateLimitMiddleware             # Middleware
php fw make:request StorePostRequest                  # Form request
php fw make:spa                                       # Vue 3 + TypeScript SPA
```

### Database

```bash
php fw migrate                    # Run pending migrations
php fw migrate:status             # Show migration status
php fw migrate:rollback           # Rollback last batch
php fw migrate:rollback --step=3  # Rollback N migrations
php fw migrate:fresh              # Drop all + re-migrate
php fw migrate:fresh --seed       # Fresh + seed
php fw db:seed                    # Run seeders
```

### Inspection & Debugging

```bash
php fw model:inspect Post                             # Model details
php fw route:for post                                 # Routes for a feature
php fw db:status                                      # Database state
php fw test:for post                                  # Tests for a feature
php fw error:explain "error message"                  # Error diagnostics
php fw routes:list                                    # All routes
```

### AI Context

```bash
php fw ai:map                     # Generate project map
php fw ai:context posts           # Dump feature files
php fw ai:context posts --compact # Dump without comments
php fw ai:context posts --json    # Machine-readable
php fw ai:next                    # Suggest next step
```

### Validation & Testing

```bash
php fw check                      # Conventions + architecture + PHPStan
php fw fix                        # Auto-correct violations
php fw test                       # Run all tests
php fw test --filter=PostTest     # Run specific tests
```

### Production

```bash
php fw optimize                   # Cache routes + config
php fw optimize:clear             # Clear all caches
php fw config:cache               # Cache configuration
php fw route:cache                # Cache routes
```

---

## Performance

Benchmarked with FrankenPHP worker mode on Apple M2:

| Metric | Result |
|--------|--------|
| Requests/sec | 40,058 |
| Average Latency | 5.15ms |
| Memory Stability | Zero leaks after 1.2M requests |

### Running with FrankenPHP (Recommended)

```bash
frankenphp php-server --listen :8080 --worker public/index.php
```

### Development Server

```bash
php fw serve                  # localhost:8000
php fw serve --port=8080      # Custom port
```

---

## Documentation

- [Controllers](docs/controllers.md) — Request handling, response helpers, FormRequest usage
- [Models](docs/models.md) — Active Record, queries, relationships, scopes
- [Views](docs/views.md) — Templates, layouts, sections, helpers
- [View Helpers](docs/helpers.md) — `$Str`, `$DateTime`, `$Arr`, and all template functions
- [Routing](docs/routing.md) — Route definitions, typed middleware, groups
- [Middleware](docs/middleware.md) — Writing middleware, built-in middleware
- [Validation](docs/validation.md) — Typed rules, FormRequest, all available rules
- [Result & Option](docs/result-option.md) — Exhaustive error handling with `match()`
- [Database & Migrations](docs/database.md) — Schema, column types, foreign keys, seeders
- [Authentication](docs/authentication.md) — Session auth, API tokens, abilities
- [CQRS](docs/cqrs.md) — Commands, queries, handlers
- [Caching](docs/caching.md) — File, APCu, tiered caching, fragment caching
- [Service Providers](docs/providers.md) — Container bindings, lifecycle hooks
- [CLI Reference](docs/cli.md) — All commands with flags
- [Testing](docs/testing.md) — Running tests, writing tests, architecture tests
- [PHP 8.5 Features](docs/php85-features.md) — `array_first()`, pipe operator, `#[\NoDiscard]`
- [Production Hosting](docs/production-hosting.md) — FrankenPHP, Nginx, OPcache, deployment
- [Upgrading from v2](UPGRADE.md) — Breaking changes and migration steps

## Requirements

- PHP 8.5+
- Composer
- SQLite, MySQL, or PostgreSQL

## License

MIT License. See [LICENSE](LICENSE) for details.
