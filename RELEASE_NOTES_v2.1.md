# VibeFW v2.1 Release Notes

**Released:** March 2026 | **Requires:** PHP 8.4+

v2.1 is the largest single release in VibeFW's history. Building on the concurrency architecture introduced in v2.0, it ships the full SPA scaffolding stack, an extensive security hardening pass, a turnkey project setup experience, and 13 patch versions worth of correctness and reliability fixes.

## What Changed in v2.1

**For new projects** — `composer create-project velkymx/vibefw my-app` now gets you a fully working app in one command. No manual `.env` setup, no key generation, no empty database.

**For SPA projects** — `php fw make:spa` scaffolds a complete Vue 3 + TypeScript + Vite frontend with authentication, routing, and VibeUI components in a single interactive command. `php fw dev` then starts both servers concurrently.

**For all projects** — 30+ security fixes land automatically on `composer update`. Nothing breaks; everything gets safer. Highlights include a complete `Sanitizer` rewrite, Fiber instance leak elimination, timing-attack hardening in auth flows, and storage permission tightening across the board.

**New in the box** — `Fw\Support\Hash` for future-proof password hashing, `Fw\Auth\EmailVerification` for confirmation flows, five new CLI commands, and configurable CORS origins.

> **Upgrading from v2.0?** v2.1 is fully backwards compatible — run `composer update velkymx/vibefw` and you're done. See the [Upgrading from v2.0](#upgrading-from-v20) section at the bottom of this document for recommended (optional) adoptions.

---

---

## What's New

### SPA Scaffolding — `php fw make:spa`

Run one command to scaffold a complete, production-ready Vue 3 + TypeScript + Vite frontend wired to a PHP API backend.

```bash
php fw make:spa
```

What you get:
- **Vue 3 + TypeScript** frontend in `/frontend/` using **VibeUI 0.7+** components
- **4-page starter** — Home, Login, Register, Dashboard — with dark mode support throughout
- **Full auth flow** — Bearer token login/register/logout, Axios 401 interceptor for server-side revocation
- **Vue Router 5** with authenticated route guards
- **Pinia** state management
- **Vite 7** dev server with API proxy to `localhost:8000`
- **PHP API controllers** — `Api/Auth/LoginController`, `Api/Auth/RegisterController`, `Api/StatsController`
- **Database migrations** installed and run (users, jobs, remember token, password resets, personal access tokens, email verifications)
- **CORS** pre-configured for the Vite dev server
- **TypeScript API types** — typed interfaces for all API responses
- **Vitest + Playwright** test scaffolding
- **Frontend README** with auth flow, API reference, and VibeUI cheat sheet

After scaffolding, start the full stack with:

```bash
php fw dev
```

---

### `php fw dev` — Concurrent Dev Server

New command that runs the PHP backend and Vite frontend concurrently via `pcntl_fork`, so you get a single terminal for the full stack.

```
Backend:  http://localhost:8000
Frontend: http://localhost:5173
```

Falls back gracefully if `pcntl` is unavailable, printing the commands to run separately.

---

### Turnkey Project Setup

```bash
composer create-project velkymx/vibefw my-app
cd my-app
```

That's it. The post-install hook automatically:
- Creates `.env` with a secure `APP_KEY`
- Sets up `storage/` directories
- Creates the SQLite database file

No manual `cp .env.example .env` or key generation required. Also available for cloned repos:

```bash
php fw setup
```

---

### New CLI Commands

| Command | Description |
|---|---|
| `php fw make:spa` | Scaffold Vue 3 + TypeScript SPA starter |
| `php fw dev` | Start backend + frontend dev servers concurrently |
| `php fw setup` | First-time project initialization |
| `php fw env:sync` | Sync `.env` keys to `frontend/.env.local` with `VITE_` prefix |
| `php fw security:check` | Focused security audit (permissions, debug flags, exposed files, hardcoded credentials) |

---

### `Fw\Support\Hash`

New utility class for password hashing:

```php
use Fw\Support\Hash;

$hash  = Hash::make($password);                    // PASSWORD_DEFAULT algorithm
$valid = Hash::check($password, $hash);            // true/false
$stale = Hash::needsRehash($hash);                 // true if algorithm or cost changed
$hash  = Hash::make($password, ['cost' => 12]);    // custom cost
```

Uses `PASSWORD_DEFAULT` so the algorithm automatically upgrades as PHP evolves.

---

### `Fw\Auth\EmailVerification`

Foundation class for email confirmation flows. Supports token generation, single-use verification with timing-attack protection, and automatic expiry cleanup. Opt-in — no routes or sending logic included.

---

### Queue Security: `allowClasses()`

`FileDriver` and `DatabaseDriver` now expose an explicit deserialization allowlist on top of the default HMAC verification:

```php
$queue->driver()->allowClasses([
    SendWelcomeEmail::class,
    ProcessPayment::class,
]);
```

---

### CORS Configuration

`CorsMiddleware` now reads from `$app->config('cors')` for per-project overrides:

```php
// config/app.php
'cors' => [
    'allowed_origins'      => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', '*')))),
    'supports_credentials' => true,
],
```

`make:spa` automatically writes `CORS_ALLOWED_ORIGINS` to `.env` to allow the Vite dev server.

---

## Security Hardening

v2.1 includes **30+ targeted security fixes**. Highlights by area:

### Authentication & Sessions
- **Remember token replay** — Token rotated on every use; old token invalidated immediately
- **Remember cookie `Secure` flag** — Set to `true` on HTTPS automatically, respects trusted proxy headers
- **`Auth logout()` order** — `clearRememberToken()` now runs before context teardown
- **Logout Bearer token revocation** — Token cannot be replayed after logout
- **UUID session user IDs** — `Auth::user()` and `Auth::id()` now accept both integer and string (UUID) primary keys
- **`RegisterController` TOCTOU race** — Duplicate email race caught at the DB unique constraint level

### SQL & QueryBuilder
- **SQL injection via alias expressions** — `validateIdentifier()` tightened to reject arbitrary SQL in `AS` aliases
- **SQL injection via aggregate functions** — `COUNT(*) OR 1=1; --` patterns now rejected
- **Foreign key action injection** — `onDelete()`/`onUpdate()` validate against a whitelist
- **`BelongsToMany` unquoted identifiers** — All identifiers in `eagerLoad()`, `detach()`, `pluck()` now quoted
- **`DatabaseDriver` table name injection** — Constructor validates table names at construction time
- **`Migrator` path traversal** — Migration files validated with `realpath()` to stay within migrations directory

### Cryptography & Tokens
- **`Str::ulid()` entropy** — Fixed from 50-bit to correct 80-bit per the ULID spec
- **`EmailVerification` single-use tokens** — Verification links now consumed on first use
- **`PersonalAccessToken` token hash isolation** — Rotating the configured prefix no longer invalidates existing tokens
- **`Auth::getUserFromRememberToken()` integer overflow** — `(int)` cast replaced with `ctype_digit()` + constant-time dummy comparison

### Input Validation & Sanitization
- **`Sanitizer` rewrite** — `json()`, `float()`, `url()` hardened; `stripTags()` no longer accepts an allowlist (was insecure)
- **Validator ReDoS** — `validateAlpha()` / `validateAlphaNum()` reject inputs over 65KB before reaching the Unicode regex
- **`Router::validateConstraint()` ReDoS** — Replaced blacklist approach with a whitelist that rejects all parenthesized groups
- **`QueryWatcher` ReDoS** — `normalizeQueryPattern()` capped at 8KB input

### Async & HTTP
- **SSRF in `AsyncHttp`** — `validateHost()` rejects private IPs, loopback, and cloud metadata addresses
- **`AsyncHttp` header injection** — Headers validated against `\r\n` before sending
- **`SpaAuthMiddleware` origin bypass** — Missing origin headers now rejected in production; dev mode restricted to localhost
- **`View::resolvePath()` path traversal** — View names validated with `realpath()`

### Infrastructure
- **Storage permissions** — All cache, log, queue, and config cache directories changed from `0755` → `0750`; log files set to `0640`
- **`ErrorHandler` path leakage** — Debug pages strip `BASE_PATH` from file paths
- **`StreamedResponse` security headers** — `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` now included
- **CSP `unsafe-inline` removed** — No longer included in `script-src` or `style-src`
- **CSRF silent failure** — `ensureSession()` throws `RuntimeException` on premature output instead of silently disabling CSRF protection

---

## Reliability & Correctness

### Fiber / Worker Mode
- **`Container` Fiber instance leak** — `spl_object_id()` keying replaced with `WeakMap<Fiber, array>` for automatic GC
- **`Model` strict mode leak** — `$globalStrictMode` reset between requests
- **`PasswordReset` / `Gate` / `ApiToken` static leaks** — All wired into `HttpKernel::resetState()`
- **View output buffer leak** — Exception inside a fragment cache block no longer leaves buffers open across requests
- **`Deferred` fiber resurrection crash** — `resolve()`/`reject()` check `isTerminated()` before resuming a Fiber

### Async
- **`Deferred::race()` never settles** — Replaced polling with `onSettle()` listener that fires immediately on resolution
- **`Deferred::race()` memory leak** — Losing deferreds release references after the race settles

### Cache
- **Null TTL semantics** — `set($k, $v, null)` now stores indefinitely per PSR-16 in both `FileCache` and `OpcacheCache`
- **`FileCache::gc()` orphaned lock files** — `.lock` sidecars cleaned up alongside expired entries
- **`OpcacheCache::increment()` race** — Atomic via sidecar lock file
- **`ViewCache` temp file race** — `tempnam()` replaces `uniqid()` for OS-guaranteed uniqueness

### Model & Database
- **`Blueprint::foreignId()` missing index** — Index created automatically on foreign key columns
- **`BelongsToMany::contains()` loose comparison** — Changed to strict to prevent authorization bypass on `'1' == 1`
- **`Connection::transaction()` exception loss** — Original exception always re-thrown even if `rollBack()` also throws
- **`Connection::commit()`/`rollBack()` underflow** — Throws `LogicException` with no active transaction
- **`Model` JSON cast** — Invalid JSON throws `JsonException`; null DB values in `json`/`array` casts return `[]`
- **`Model::castToClass()` arbitrary class instantiation** — Restricted to `Fw\` and `App\` namespaces
- **`PersonalAccessToken` model** — Restored and completed: `isValid()`, `cannot()`, `touchLastUsed()`, `revoke()`, `user()`, hierarchical ability matching
- **N+1 detection** — Lazy-loaded relationships in debug mode emit a warning

### Collections & Utilities
- **`Collection::first()`/`last()` falsy values** — `0`, `""`, `false` no longer treated as missing
- **`DateTime::equals()`** — Fixed to value comparison; `create()` zeroes microseconds for deterministic equality
- **`Str` static cache bounds** — Capped at 512 entries each to prevent unbounded memory growth in worker mode
- **`Worker`** — Log rotation at 10MB; stack traces capped at 30 frames; signal handlers restored on shutdown
- **`QueryBuilder::paginate()`** — `$perPage = 0` throws `InvalidArgumentException` instead of division by zero

---

## Architecture Changes

### Framework / App Layer Separation
The entire `src/` directory is now free of `App\` namespace imports:

- `Auth::setUserModel()` — configures the user model class
- `ApiToken::setTokenModel()` — configures the token model class
- `Gate::setPolicyNamespace()` — configures the policy namespace
- `composer.json` — `App\\` moved from `autoload-dev` to `autoload` so production deploys work

### Static Analysis & Code Quality
- PHPStan level 8 passes with a baseline for pre-existing annotations
- PHP-CS-Fixer passes across all 216+ files
- Architecture tests enforce layer boundaries (Controllers cannot access PDO, `src/` cannot import `app/`)

### CI Pipeline
- PHPStan, PHP-CS-Fixer, security scan, `composer audit`, unit/integration tests with coverage, architecture tests
- Coverage threshold: 50% minimum enforced
- `.gitattributes` keeps Composer dist archives lean (excludes tests, CI, PHPStan config)

---

## Upgrading from v2.0

v2.1 is **fully backwards compatible** with v2.0.

```bash
composer update velkymx/vibefw
```

### Recommended Adoptions

**Password hashing** — replace direct `password_hash()` calls:
```php
use Fw\Support\Hash;

// Before
$hash = password_hash($password, PASSWORD_DEFAULT);

// After
$hash = Hash::make($password);
if (Hash::needsRehash($hash)) {
    $hash = Hash::make($newPassword);
}
```

**CORS in production** — restrict allowed origins:
```php
// config/app.php
'cors' => [
    'allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', '*')))),
],
```

```env
# .env
CORS_ALLOWED_ORIGINS=https://yourapp.com,https://www.yourapp.com
```

**Auth user model** — if you use a custom User model:
```php
// In a service provider
Auth::setUserModel(App\Models\CustomUser::class);
```

---

## Performance

v2.1 maintains the benchmark results established in v2.0:

| Metric | Result |
|---|---|
| Requests/sec (FrankenPHP, 4 workers, M2) | 40,058+ |
| Avg latency | 5.15ms |
| Memory stability | Stable over 1.2M+ requests |

The focus of v2.1 was correctness and developer experience. Performance tuning is planned for v2.2.
