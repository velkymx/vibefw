# START HERE

Best practices for this part of the codebase are to use the following CLI commands.

- `php fw check` — single entry point: conventions + architecture + static analysis
- `php fw fix` — auto-correct the most common convention violations
- `php fw validate:all` — run every validator (config, security, style, analysis)
- `php fw validate:config` — validate config files against their schemas
- `php fw validate:security` — scan for vulnerabilities (RCE, SQLi, XSS, hardcoded creds, debug code)
- `php fw security:check` — basic application security audit
- `php fw db:status` — connection state + pending migrations (first thing to run on DB errors)
- `php fw routes:list` — confirm a route actually registered when "route not found" surfaces
- `php fw model:inspect Post` — inspect a model's table, fillable, casts, and relationships
- `php fw error:explain "<paste error message>"` — parse an error and suggest a fix
- `php fw ai:next` — suggest the next step based on current project state
- `php fw ai:context post` — dump every file for a feature (for pasting into an AI chat)

# BEWARE

Only read past here if you are unable to use the CLI.

# Troubleshooting

The `php fw` CLI is your primary tool for diagnosing problems. Before diving into code, use the inspection and validation commands to locate the issue.

## Quick Diagnostics

Run these first when something is wrong:

```bash
php fw check          # Conventions + architecture + PHPStan in one pass
php fw db:status      # Database connection state + pending migrations
php fw routes:list    # All registered routes + methods + middleware
```

## Inspecting a Feature

When a feature behaves unexpectedly, pull up everything related to it:

```bash
# What routes does this feature have?
php fw route:for post

# What does the model look like (table, fillable, casts, relations, row count)?
php fw model:inspect Post

# What test files exist for this feature?
php fw test:for post

# Dump all source files for the feature (model, controller, views, tests)
php fw ai:context post
```

## Decoding Error Messages

Paste the error message directly into the CLI — it parses framework-specific errors and suggests a fix:

```bash
php fw error:explain "Mass assignment violation on Post"
php fw error:explain "No route matched GET /posts/create"
php fw error:explain "Call to undefined method App\Models\Post::published()"
```

## Database Problems

### Pending migrations

```bash
php fw migrate:status    # See which migrations have/haven't run
php fw migrate           # Run pending migrations
```

### Foreign key / constraint failures

```bash
php fw model:inspect Post    # Confirm foreignId column types match the referenced table
```

### Connection errors

Check `.env` settings and confirm the driver is reachable:

```bash
php fw db:status
```

## Routing Problems

See [routing.md](routing.md) for the full routing reference.

### 404 — Route not found

```bash
php fw routes:list                    # Is the route registered?
php fw routes:list --method=GET       # Filter by method
php fw route:for <keyword>            # Find routes for a feature topic
```

Common causes:
- Missing route in `config/routes.php`
- Route cached with stale data — run `php fw route:clear` then re-register

### Route hits wrong controller

```bash
php fw routes:list    # Check the order — first match wins
```

More-specific routes (e.g. `/posts/create`) must be registered before wildcard routes (e.g. `/posts/{id}`).

## Validation Problems

See [validation.md](validation.md) for typed rules and FormRequest patterns.

### Silent pass when it should fail

String pipe rules (`'required|min:3'`) are not supported — they are silently ignored. Use typed rules:

```php
// Wrong — silently ignored
'title' => 'required|min:3'

// Correct — fatal error on typo, enforced at runtime
'title' => [new Required, new MinLength(3)]
```

### FormRequest not found

```bash
php fw check    # PHPStan will surface missing class or wrong namespace
```

## Cache Problems

### Stale data / old routes or config served

```bash
php fw cache:clear           # Clear all caches
php fw cache:clear --views   # Clear only view cache
php fw route:clear           # Clear route cache
php fw optimize:clear        # Clear all optimized caches at once
```

### Route cache out of sync after editing `config/routes.php`

```bash
php fw route:clear
php fw route:cache    # Re-cache after clearing
```

## Code Quality & Convention Violations

### Run all checks

```bash
php fw check              # Conventions + architecture + PHPStan
php fw validate:all       # Config + security + style + analysis
php fw validate:security  # Security scan only (eval, unserialize, SQL injection, etc.)
```

### Auto-fix common violations

```bash
php fw fix    # Corrects naming conventions, missing $fillable, wrong return types
```

### Architecture violation (layer test failing)

```bash
php fw check    # Shows which layer rule is violated and in which file
```

Common violations:
- Controller importing `PDO` or `Connection` directly — use Models instead
- Model importing a Controller class — remove the import
- App code (`app/`) importing framework internals (`src/`) via prohibited paths

## Test Failures

See [testing.md](testing.md) for factories, database setup, and architecture tests.

### Run a specific test

```bash
php fw test --filter=PostTest
php fw test --filter=itCreatesAPost
```

### Find tests for a feature

```bash
php fw test:for post    # Lists all test files related to "post"
```

### Generate missing tests

```bash
php fw make:test Post    # Generates unit + feature tests from the model
```

### Check test quality

```bash
composer test:mutation    # Mutation testing — catches tests that don't actually assert anything
```

## Worker Mode (FrankenPHP) Problems

### State leaking between requests

Static properties are shared across requests in worker mode. Use `RequestContext`:

```php
// Wrong — leaks between requests
private static ?User $user = null;

// Correct — scoped to current request
RequestContext::current()->set('user', $user);
```

### Memory growing unbounded

Reduce the worker loop's `$maxRequests`, or call `Model::clearMetadataCache()` more frequently. Check for closures or listeners that accumulate state.

## Getting a Project Overview

When joining a codebase or resuming work after a break:

```bash
php fw ai:map     # Auto-generate a project map summarising all features, models, routes
php fw ai:next    # Suggest the next logical step based on project state
```
