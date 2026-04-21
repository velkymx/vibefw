# START HERE

Best practices for upgrading are to use the following CLI commands.

- `php fw check` — show every convention, architecture, and static-analysis violation in the project
- `php fw fix` — auto-correct the common ones (`$guarded` → `$fillable`, missing `declare(strict_types=1)`, etc.)
- `php fw validate:all` — run every validator after you think the upgrade is done
- `php fw test` — run the test suite; existing tests are your migration safety net

Run them in that order — `check` tells you what's wrong, `fix` handles the mechanical cleanup, `validate:all` catches what's left, `test` confirms behaviour. Iterate until `check` is clean.

# BEWARE

Only read past here if you are unable to use the CLI.

# Upgrading from Fw v2 to v3

Fw 3.0 is a clean break. The patterns below are **removed**, not deprecated.

## Breaking Changes

### PHP 8.5 Required

Update `composer.json`:
```json
"require": {
    "php": ">=8.5"
}
```

### Router — `dispatchLegacy()` Removed

`Router::dispatchLegacy()` is gone. `Router::dispatch()` is the only entry point, returning `Result<array{...}, RouteError>`.

**Before:**
```php
[$handler, $params] = $router->dispatchLegacy($method, $uri);
```

**After:**
```php
$router->dispatch($method, $uri)->match(
    ok:  fn($match) => $kernel->run($match['handler'], $match['params']),
    err: fn($err)   => $this->renderRouteError($err),
);
```

The framework's `HttpKernel` already uses the Result form, so applications that route through `HttpKernel::handle()` need no change.

### Fw\Database\Model — Deprecated

`Fw\Database\Model` emits `E_USER_DEPRECATED` on first instantiation per subclass. Migrate to `Fw\Model\Model`, which returns `Option<T>` from reads and `Result<T, ModelError>` from writes.

| Legacy (`Fw\Database\Model`) | Replacement (`Fw\Model\Model`) |
|------------------------------|-------------------------------|
| `Post::find($id)` returns `?static` | Returns `Option<static>` — use `->match(some: ..., none: ...)` |
| `Post::findOrFail($id)` throws `RuntimeException` | `Post::find($id)->unwrapOrElse(fn() => throw ...)` or rely on controller-level `notFound()` |
| `$model->save()` returns `bool` | Returns `Result<static, ModelError>` — use `->match(ok: ..., err: ...)` |
| `protected static string $table = ''` | `protected static ?string $table = 'posts'` (nullable, inferred from class name) |
| `Model::setConnection($conn)` in bootstrap | Connection resolved from container — no bootstrap wiring needed |
| `$guarded` property | Removed — use `$fillable` exclusively |

**Before:**
```php
namespace App\Models;

use Fw\Database\Model;

class Post extends Model
{
    protected static string $table = 'posts';
}

$post = Post::find(1);
if ($post === null) {
    return $this->notFound();
}
```

**After:**
```php
namespace App\Models;

use Fw\Model\Model;

class Post extends Model
{
    protected static ?string $table = 'posts';
    protected static array $fillable = ['title', 'content', 'user_id'];
}

return Post::find(1)->match(
    some: fn($post) => $this->view('posts.show', ['post' => $post]),
    none: fn()      => $this->notFound(),
);
```

Legacy subclasses keep working, but the deprecation notice will surface in logs. Plan the migration at your own pace — `Fw\Database\Model` has no scheduled removal date yet.

### Controller — Removed Methods

The following methods are removed: `validate()`, `dbQuery()`, `dbQueryOne()`, `input()`, `only()`, `except()`, `has()`, `abort()`.

| Removed | Replacement |
|---------|-------------|
| `$this->validate($request, [...])` | `FormRequest::fromRequest($request)` |
| `$this->dbQuery()` | Inject repository or use Model directly |
| `$this->dbQueryOne()` | Inject repository or use Model directly |
| `$this->input()` | `$request->input()` or FormRequest properties |
| `$this->only()` | FormRequest `->only([...])` |
| `$this->except()` | FormRequest `->except([...])` |
| `$this->has()` | `$request->has()` |
| `$this->abort()` | Return `$this->notFound()` / `$this->forbidden()` |

**Before (v2):**
```php
public function store(Request $request): Response
{
    $validation = $this->validate($request, [
        'title' => 'required|min:3',
    ]);
    $post = Post::create($validation->getValue());
}
```

**After (v3):**
```php
public function store(Request $request): Response
{
    try {
        $data = StorePostRequest::fromRequest($request);
    } catch (ValidationException $e) {
        return $this->view('posts.create', ['errors' => $e->errors]);
    }
    $post = Post::create($data->toArray());
}
```

Run `php fw fix` to auto-convert common patterns.

### Validation — Typed Rules

String pipe rules are gone. Use typed Rule objects in FormRequest:

**Before (v2):** `'title' => 'required|min:3|max:255'`

**After (v3):**
```php
'title' => [new Required, new MinLength(3), new MaxLength(255)]
```

Available rules: `Required`, `MinLength`, `MaxLength`, `Email`, `Url`, `In`, `InEnum`, `Regex`, `Between`, `Unique`, `Exists`, `Confirmed`.

### Result/Option — Exhaustive Handling

These methods are removed:

| Removed | Replacement |
|---------|-------------|
| `->unwrap()` | `->match(ok: ..., err: ...)` or `->match(some: ..., none: ...)` |
| `->getValue()` | `->match(ok: fn($v) => $v, err: ...)` |
| `->getError()` | `->match(ok: ..., err: fn($e) => $e)` |

**Before (v2):**
```php
$user = User::find($id);
if ($user->isSome()) {
    $user = $user->unwrap();
}
```

**After (v3):**
```php
User::find($id)->match(
    some: fn($user) => $this->view('users.show', ['user' => $user]),
    none: fn() => $this->notFound(),
);
```

`unwrapOr()` is still available as an escape hatch.

### Model — $fillable Only

- `$guarded` is removed. Use `$fillable` exclusively.
- `enableStrictMode()` / `resetStrictMode()` are removed. Strict mode is permanently on.

**Fix:** Add all mass-assignable fields to `$fillable`. Run `php fw fix` to auto-convert.

### Router — No Closures, Response Required

- Closure route handlers throw `InvalidArgumentException` at registration.
- Controllers must return `Response` — `HttpKernel` no longer normalizes strings or arrays.
- Middleware must use class references: `$router->with(AuthMiddleware::class, ...)`.

### Routes — Typed Middleware

**Before (v2):** `middleware: ['auth']`

**After (v3):** `$router->with(AuthMiddleware::class, function (Router $r) { ... })`

## Upgrade Steps

1. Update `composer.json` to require PHP 8.5+
2. Run `php fw check` to see all violations
3. Run `php fw fix` to auto-correct common issues
4. Replace string pipe rules with typed Rule objects in FormRequests
5. Replace `unwrap()`/`getValue()`/`getError()` with `match()`
6. Replace `$guarded` with `$fillable` in all models
7. Ensure all controller methods return `Response`
8. Run `php fw check` again to verify
9. Run `composer test` to confirm all tests pass

## New v3 Commands

| Command | Purpose |
|---------|---------|
| `php fw make:schema Post` | Generate JSON schema template |
| `php fw make:resource --schema=...` | Generate full CRUD from schema |
| `php fw add:field Post slug string` | Add field to existing resource |
| `php fw make:link Post Comment --hasMany` | Wire relationship |
| `php fw model:inspect Post` | Show model details |
| `php fw route:for post` | Show routes for a feature |
| `php fw db:status` | Show database state |
| `php fw test:for post` | Find tests for a feature |
| `php fw error:explain "..."` | Parse error and suggest fix |
| `php fw check` | Validate conventions + architecture |
| `php fw fix` | Auto-correct violations |
