# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.1.19] - 2026-03-09

### Fixed (Static Analysis)

- **`Container` PHPStan level 8 failures** — `WeakMap` generic annotation used `array<class-string, object>` for fiber-scoped instances, causing PHPStan to require `class-string` at all call sites. Changed to `array<string, object>` to correctly reflect that the container supports arbitrary string binding keys, not only class names.

### Fixed (Security / CLI)

- **`SecurityCheckCommand::fileperms()` called without `file_exists()` guard** — On fresh projects where `.env` doesn't yet exist, `fileperms()` returned `false`, producing a misleading permissions warning. Now skips the check gracefully with a warning.
- **`SecurityCheckCommand::glob()` result not guarded against `false`** — `glob()` can return `false` on OS-level failure. Iterating over `false` throws a `TypeError` under `strict_types`. Guarded with `?: []`.
- **`SecurityCheckCommand::file_get_contents()` result not checked** — Passing `false` to `preg_match()` under `strict_types` throws a `TypeError`. Unreadable config files are now skipped with `continue`.
- **`DevCommand::passthru()` shell argument injection** — `$frontendDir` was interpolated directly into shell strings without `escapeshellarg()`. Paths with spaces or shell metacharacters would break or be exploitable. All `passthru()` calls now use `escapeshellarg()`.
- **`DevCommand::die()` on fork failure** — `die("Could not fork")` bypassed the framework's error handling and killed the process abruptly. Replaced with `$this->error()` + `return`.
- **`SyncEnvCommand` value not trimmed** — `.env` values were written to `frontend/.env.local` with trailing whitespace (including `\r` on Windows) and surrounding quotes intact (e.g. `APP_NAME="My App"` → `VITE_APP_NAME="My App"` with embedded quotes). Values are now fully trimmed and unquoted.
- **`Command::ask()` called with hidden-input argument that doesn't exist** — `ScaffoldSpaCommand::setupDatabase()` passed a third `true` argument to `ask()` to suppress terminal echo for the database password, but `ask()` only accepts two parameters. Added a new `secret()` method to the `Command` base class that uses `stty -echo` on POSIX systems for true hidden input. `ScaffoldSpaCommand` now uses `secret()`.
- **`make:spa` CORS not configured for Vite dev server** — `updateConfiguration()` read `config/middleware.php` into a variable but never wrote anything back, leaving CORS silently unconfigured. Now writes `CORS_ALLOWED_ORIGINS` to `.env` and `config/app.php` exposes a `cors.allowed_origins` array read from that variable. `CorsMiddleware` already reads `$app->config('cors')`, so no middleware changes were needed.
- **`make:spa --test` incomplete verification** — `runVerification()` only checked 3 of the 6 migration stubs installed by `setupDatabase()`. Migrations `0003`, `0004`, and `0007` were installed but never verified, allowing `--test` to pass with missing files. All 6 are now checked.

### Fixed (Correctness)

- **`Hash::make()` hardcoded `PASSWORD_BCRYPT`** — Using `PASSWORD_DEFAULT` ensures the algorithm automatically upgrades as PHP evolves. Added optional `$options` array for cost tuning and a `needsRehash()` static method.

## [2.1.18] - 2026-03-09

### Changed (Architecture)

- **Migrations moved to `make:spa` scope** — All 6 database migrations (users, jobs, remember_token, password_resets, personal_access_tokens, email_verifications) are now stored as stubs in `stubs/spa/database/migrations/` and only installed when `php fw make:spa` is run. A fresh `composer create-project` ships with an empty `database/migrations/` directory — no tables are created until the developer explicitly scaffolds the SPA.
- **`scripts/setup.php` no longer runs migrations** — The post-create-project hook now only creates `.env`, generates keys, sets up storage dirs, and creates the SQLite file. Migrations are deferred to `make:spa`.
- **`php fw setup` no longer runs migrations** — Removed `--no-migrate` option since there are no migrations to run on a bare project.

## [2.1.17] - 2026-03-08

### Added (Developer Experience)

- **Turnkey project setup** — `composer create-project velkymx/vibefw my-app` now automatically creates `.env` with secure keys, sets up storage directories, creates the SQLite database, and runs migrations. Zero manual configuration required.
- **`php fw setup` command** — CLI command for first-time project initialization. Supports `--force` (overwrite .env) and `--no-migrate` (skip migrations). Also available as `php scripts/setup.php` for standalone use.
- **`.gitattributes`** — Excludes dev-only files (tests, PHPStan, infection, CI configs) from Composer dist archives, keeping `create-project` downloads lean.
- **`.env.example` updated** — APP_NAME changed from "VibeFw Framework" to "VibeFW".

## [2.1.16] - 2026-03-08

### Added (Documentation)

- **SPA Quick Start guide** — New `docs/spa-quick-start.md` with end-to-end walkthrough from install to production deployment, including how to add new pages and API endpoints.
- **Comprehensive inline comments in all SPA stubs** — Every frontend stub (main.ts, router/index.ts, MainLayout.vue, Login.vue, Register.vue, Dashboard.vue, Home.vue, NotFound.vue, App.vue, Dashboard.test.ts) now has detailed doc comments explaining auth flow, API contracts, route guards, dark mode, and extension points.
- **TypeScript API types stub** — New `src/types/api.ts.stub` generates typed interfaces for all API responses (User, LoginResponse, DashboardStats, ValidationErrorResponse, etc.).
- **Frontend README stub** — New `README.md.stub` generates a comprehensive README in `/frontend/` with tech stack, project structure, auth flow, API reference, VibeUI cheat sheet, and troubleshooting guide.
- **README.md updated** — Added links to SPA Quick Start guide and VibeUI component reference.
- **ScaffoldSpaCommand updated** — Now copies the new types and README stubs, creates `src/types/` directory.

## [2.1.15] - 2026-03-08

### Fixed (make:spa)

- **Raw `<i class="bi">` icons replaced with `<VibeIcon>`** — All stubs now use the `<VibeIcon icon="name" />` component instead of raw Bootstrap Icon markup. Affected: MainLayout.vue (sidebar, header), Home.vue (feature cards).
- **Quill dependency restored** — Quill is required by `VibeFormWysiwyg` and was incorrectly removed. Restored in `package.json` and `App.vue` CSS imports.
- **AI reference docs updated for VibeIcon** — Added full `VibeIcon` component reference (props, examples) to `VIBE-UI-AI.md`. Updated `llms-ui.txt` with VibeIcon usage, complete component inventory for VibeUI 0.7+, and link to canonical docs.

## [2.1.14] - 2026-03-08

### Fixed (make:spa)

- **VibeUI version pinned to ^0.6.0** — Updated to `^0.7.0` to match current VibeUI best practices.
- **Unused quill dependency** — `quill` rich text editor was included in `package.json` but never used in any stub. Removed.
- **Raw HTML instead of VibeUI components** — Home.vue used `<div class="card">` and `<a class="btn">` instead of `<VibeCard>` and `<VibeButton>`. NotFound.vue used raw `<router-link class="btn">` instead of `<VibeButton :to="...">`. All replaced with proper VibeUI components.
- **Hardcoded colors break dark mode** — Auth pages used `background-color: #f8f9fa`, sidebar used `background-color: #fff` and `color: #333`, hero gradient used literal hex values. All replaced with Bootstrap CSS variables (`var(--bs-body-bg)`, `var(--bs-body-color)`, `var(--bs-tertiary-bg)`, etc.) for full dark mode support.
- **Emoji icons instead of Bootstrap Icons** — Home.vue feature cards used emoji (⚡🎨🔐). Replaced with `<i class="bi bi-...">` for consistent rendering across platforms.
- **Inline styles on avatar element** — MainLayout.vue used `style="width: 38px; height: 38px"` directly on the element. Moved to a `.user-avatar` CSS class.
- **App.vue CSS selector mismatch** — `#app` selector in `<style>` did not match the `#app-root` element in the template. Fixed to `#app-root`. Removed unused quill CSS import.
- **Dashboard test assertion wrong** — Expected `"Vibe Stack Dashboard"` but actual heading is `"Welcome back"`. Fixed to match.
- **`stubs/spa/` incorrectly gitignored** — The scaffold source templates were in `.gitignore`, meaning they would not be included in the framework package. Removed from ignore list (only generated output directories are ignored).
- **`llms-ui.txt` contained incorrect API references** — Referenced nonexistent `useToast()` composable, used wrong component names (`Input`/`Button` instead of `VibeFormInput`/`VibeButton`), and omitted rules about global registration. Completely rewritten to match VibeUI 0.7+.

## [2.1.13] - 2026-03-08

### Fixed (Correctness)

- **`DateTime::equals()` compares object identity instead of value** — Used `===` on `DateTimeImmutable` objects, which compares identity not value. Two `DateTime::create()` calls with identical parameters would be unequal due to inherited microseconds from `'now'`. Now compares timestamp + microseconds explicitly. `create()` also zeroes microseconds.
- **`ScaffoldSpaTest` risky test warning and PHP 8.5 deprecation** — Test printed output without capturing it (risky) and used deprecated `setAccessible()` (unnecessary since PHP 8.1). Fixed with `ob_start()`/`ob_end_clean()` and removed the call.

### Improved

- **PHPStan level 8 now passes cleanly** — Added baseline for 641 pre-existing type annotation warnings. New code is still checked at level 8; only grandfathered patterns are exempted.
- **Code style fully passing** — All 216 files now pass php-cs-fixer checks.

## [2.1.12] - 2026-03-08

### Fixed (Security)

- **`QueryBuilder::validateIdentifier()` SQL injection via alias expressions** — The alias regex `(.+?)` matched arbitrary SQL expressions as valid identifiers. An attacker could craft column names like `"1; DROP TABLE users -- AS x"` that would bypass validation. Now validates the expression part against safe identifier and aggregate function patterns only.

### Fixed (Correctness)

- **`Auth::user()` rejects UUID session user IDs** — The session user ID check was `is_int()` only, rejecting string-based UUIDs immediately. Applications using UUID primary keys could never restore sessions. Now accepts both positive integers and non-empty strings.
- **`Deferred::race()` fundamentally broken** — Used `EventLoop::defer()` to poll deferreds, but this only ran once per tick. If no deferred resolved on the first tick, race() would never settle. Replaced with a new `onSettle()` listener mechanism that fires callbacks immediately when a deferred settles, regardless of event loop timing.
- **`AsyncDatabase` documentation misleading** — Docblock implied true non-blocking I/O. Added explicit WARNING that PDO is synchronous and recommended amphp/mysql or reactphp/mysql for genuine async database access.
- **`Auth::id()` return type too narrow for UUIDs** — Returned `?int`, which silently truncated string UUIDs. Widened to `string|int|null`.

### Fixed (Architecture)

- **`composer.json` autoload misconfiguration** — `App\\` and `Database\\Seeders\\` namespaces were in `autoload-dev`, meaning `composer install --no-dev` in production would not autoload application code. Moved to `autoload`.
- **`Gate` hardcoded `App\Policies` namespace** — Policy classes were always resolved from `App\Policies\{Model}Policy`, making it impossible to use a different namespace. Added `Gate::setPolicyNamespace()` for configuration. `Gate::check()` and `Policy::before()` now accept `Fw\Model\Model` instead of `App\Models\User`.
- **`TokenGuard` and `ApiToken` coupled to application models** — Both imported `App\Models\User` and `App\Models\PersonalAccessToken` directly, violating the framework/app layer boundary. Now use `Fw\Model\Model` as type constraints. `ApiToken::setTokenModel()` allows configuring the token model class.
- **`Auth` coupled to `App\Models\User`** — Imported and type-hinted `App\Models\User` throughout. Added `Auth::setUserModel()` for configuration. The entire `src/` directory is now free of `App\` namespace imports.

## [2.1.11] - 2026-03-08

### Fixed (Security)

- **`PasswordReset` / `EmailVerification` timing attack mitigation fragile** — Both `findByToken()` and `verify()` called `hash_equals()` for constant-time comparison but discarded the return value. Static analysis would flag this as dead code, and a future refactor could remove the "unused" call, silently breaking the timing protection. Return value now captured in `$_`.
- **`Blueprint` foreign key action SQL injection** — `onDelete()` and `onUpdate()` accepted arbitrary strings interpolated directly into SQL (`ON DELETE {$action}`). Now validated against a whitelist: `CASCADE`, `SET NULL`, `RESTRICT`, `NO ACTION`, `SET DEFAULT`.
- **`Migrator` migrations table uses hardcoded quoting** — `ensureMigrationsTable()` used hardcoded double quotes for SQLite/PostgreSQL and backticks for MySQL instead of `quoteIdentifier()`. Inconsistent with the rest of the Migrator and breaks if the quoting convention changes.
- **`BelongsToMany::contains()` loose comparison** — Used `in_array($id, ..., false)` (loose), meaning `'1' == 1` would match. Could cause authorization issues if IDs distinguish resources. Changed to strict comparison.
- **`AuthMiddleware` redirect URL allows `javascript:` scheme** — `isSafeRedirectUrl()` checked for host presence but didn't validate the URL scheme. `parse_url('javascript:alert(1)')` has no host, passing the safety check. Now whitelists only `http` and `https` schemes.
- **`DatabaseDriver` raw SQL uses unquoted table name** — `pop()`, `size()`, and `clear()` interpolated `$this->table` directly into SQL. While the constructor regex validates the name, this was inconsistent with framework rules. Now uses a pre-computed `$this->quotedTable` via `quoteIdentifier()`.
- **`QueryWatcher` regex DoS on large IN clauses** — `normalizeQueryPattern()` used `/IN\s*\([^)]+\)/i` which is slow on queries with 10K+ values. Changed to `[^)]*` and added 8KB input cap.

### Fixed (Correctness)

- **`Router::url()` parameter key not regex-escaped** — User-provided parameter keys were interpolated into a regex pattern without `preg_quote()`. Keys containing regex metacharacters (e.g. `a|b`) would create an alternation. Now escaped.
- **`Pipeline` creating `CanMiddleware` twice** — The `can` middleware was first instantiated via generic `make()`, then immediately discarded and re-created with the correct `permissions` parameter. Now checks class before instantiation.
- **`Worker` unbounded stack trace in failed job log** — `getTrace()` captured the full stack without limits. Deeply nested traces could cause excessive log growth. Capped at 30 frames.
- **`Collection::first()` / `last()` returns wrong value for falsy items** — Used `reset() ?: fallback` which treats `0`, `""`, `false` as missing and falls through to the backup path. A collection of `[0, 1, 2]` would not return `0` correctly. Simplified to direct array indexing.
- **`ScaffoldSpaTest` destroys app directory permanently** — The test backed up `app/` but had no `tearDown()` to restore it, causing `PersonalAccessToken.php` to disappear for subsequent test runs. Added tearDown that restores from backup.

## [2.1.10] - 2026-03-07

### Fixed (Security)

- **Storage directory permissions too permissive** — Cache directories (`FileCache`, `OpcacheCache`), log directories (`Logger`, `LogMiddleware`, `Worker`), view cache, config cache, route cache, and queue directories were all created with `0755` (world-readable). These directories may contain sensitive data (cached user data, database passwords, request logs, serialized job payloads). Changed to `0750` (owner+group only) across all storage-related `mkdir()` calls. Log files are now explicitly set to `0640` on creation.
- **`StreamedResponse` missing security headers** — `StreamedResponse` only sent `Content-Type` and `X-Accel-Buffering`, omitting security headers that the regular `Response` class provides via `securityHeaders()`. Added `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, and `Referrer-Policy` as defaults.
- **`ErrorHandler` debug page leaks server paths** — Debug-mode error pages exposed full absolute file paths (e.g. `/var/www/myapp/src/Core/Router.php`), revealing the server's directory structure. Now strips `BASE_PATH` prefix so paths display as relative (e.g. `src/Core/Router.php`).

### Fixed (Correctness)

- **`FileDriver` silent JSON corruption** — `json_decode()` in `pop()` and `release()` did not use `JSON_THROW_ON_ERROR`. Corrupt job files in `pop()` were silently deleted with no logging. In `release()`, corruption caused a silent no-op, leaving the job permanently reserved. Now uses `JSON_THROW_ON_ERROR` and logs corrupt files.
- **`make:spa` generated files added to `.gitignore`** — Files generated by the `make:spa` scaffold command (`frontend/`, `app/Controllers/Api/`, `stubs/spa/`, `storage/backups/`) are now excluded from version control.

## [2.1.9] - 2026-03-07

### Fixed (Security)

- **`Container` Fiber instance leak via `spl_object_id` reuse** — Fiber-scoped singleton instances were keyed by `spl_object_id()`, which can be reused after a Fiber is garbage collected. A new Fiber could inherit stale instances from a previous one, leaking authentication state or database connections between requests in worker mode. Replaced with `WeakMap<Fiber, array>` so instances are automatically collected when the Fiber is GC'd.
- **`Auth` remember token replay** — Successful login via a remember-me cookie did not rotate the token. An attacker who obtained a remember token (e.g. via XSS or physical access) could replay it indefinitely for 30 days. The token is now rotated on each use — the old token is invalidated and a fresh one is issued.
- **`Request::setTrustedProxies()` wildcard IP spoofing** — Setting trusted proxies to `['*']` allows any client to spoof their IP via `X-Forwarded-For`, bypassing rate limiting and IP-based access controls. Now emits a production `error_log()` warning when wildcard is configured, recommending explicit proxy IPs.
- **`OpcacheCache::increment()` race condition** — The read-then-write pattern had no locking, so concurrent requests could read the same counter value and both write `value + 1` instead of `value + 2`. Now uses a sidecar lock file for atomicity (same pattern as `FileCache::increment()`).

### Fixed (Correctness)

- **`FileCache::gc()` deletes forever-cached entries** — Entries stored with `null` TTL (store forever) had `'expires' => null`. The GC check `$data['expires'] < time()` compared `null < int`, which PHP 8 evaluates as `true` (null → 0), causing all forever-cached entries to be garbage collected. Now explicitly skips entries where `expires` is `null`.
- **`FileCache::gc()` orphaned lock files** — When GC deleted expired cache files, the corresponding `.lock` sidecar files (used by `increment()`) were left behind. Now cleans up lock files alongside their data files.
- **`Deferred::race()` memory leak** — Losing deferreds in a `race()` call retained references to all input deferreds via closures, preventing garbage collection of potentially large result values. References are now cleared when the race settles.
- **`PersonalAccessToken` type mismatch** — `protected static string $table` did not match parent `Model`'s `?string` type, causing a fatal error under PHP 8.5. Changed to `?string`.
- **`Worker` signal handler leak** — Signal handlers registered via `pcntl_signal()` were never restored when the worker stopped, leaving stale handlers that could interfere with parent processes or test runners. Now restores `SIG_DFL` on shutdown.
- **`Worker` unbounded log growth** — `failed_jobs.log` grew without limit. Added automatic log rotation at 10MB, keeping one backup file.
- **`Request::rawBody()` size limit off-by-8KB** — The body size check used `>` instead of `>=`, allowing up to 8191 bytes over `$maxBodySize` (one chunk's worth). Changed to `>=` for exact enforcement.
- **`SecurityPatternTest` false positive on quoted identifiers** — The SQL concatenation security test flagged `BelongsToMany` queries that use properly quoted identifiers via `quoteIdentifier()`. Updated the test to recognize `$q*` prefixed variables and `$placeholders` as safe patterns.
- **`DateTime::equals()` identity comparison on value objects** — Used `===` (identity) instead of `==` (equality) to compare `DateTimeImmutable` instances. Two independently created `DateTimeImmutable` objects representing the same instant would never be `===`. Changed to `==` for correct value comparison.
- **`Model` JSON cast returns `null` instead of `[]`** — The `castAttribute()` null-to-empty-array handling only covered the `'array'` cast type, not `'json'`. A model attribute cast as `json` with a `NULL` database value returned `null` instead of `[]`, breaking code that expected an iterable. Added `'json'` to the null check.
- **`PersonalAccessToken` model incomplete** — The model was missing `$incrementing = false` and `$keyType = 'string'` (token table uses `VARCHAR(36)` primary key), causing `find()` to cast UUIDs to `0`. Also missing: `isValid()`, `cannot()`, `touchLastUsed()`, `revoke()`, hierarchical `can()` with parent/action matching (e.g. `posts:create` matched by `posts` ability), and `user()` BelongsTo relationship.
- **`LayerTest` false positive on allowed circular dependencies** — `Core <-> Model` (HttpKernel resets Model static state; Model uses Core\Config) and `Core <-> Auth` (HttpKernel resets Auth static state; Auth uses Core\RequestContext) are architectural necessities. Added both pairs to the allowed exceptions list.

## [2.1.8] - 2026-03-07

### Fixed (Security)

- **`Migrator::resolveMigration()` path traversal** — Migration file paths were not validated against the migrations directory, allowing `require_once` of arbitrary PHP files if an attacker could influence the file list. Now resolves both paths with `realpath()` and verifies the file stays within the migrations directory and has a `.php` extension.
- **`Gate::authorize()` silent denial** — Authorization failures threw `ForbiddenException` without any logging, making it impossible to audit who attempted unauthorized actions. Now logs `user`, `action`, and `target` via `error_log()` before throwing.
- **`Validator` ReDoS on alpha/alphanumeric rules** — `validateAlpha()` and `validateAlphaNum()` used Unicode regex (`\pL\pM`) without input length guards. Malformed UTF-8 sequences longer than 64KB could cause catastrophic backtracking. Now rejects inputs over 65,535 bytes before reaching the regex.
- **`Validator` regex rule crash on invalid pattern** — User-supplied regex patterns in the `regex:` validation rule could trigger PHP warnings on invalid PCRE. Now uses `@preg_match()` and returns `false` instead of crashing.
- **`Sanitizer::float()` accepts `1.2.3` as valid** — The float sanitizer's regex-based fallback allowed multiple decimal points (e.g. `1.2.3` → `1.23`). Now validates with `FILTER_VALIDATE_FLOAT` first and falls back to a strict single-decimal regex.
- **`Sanitizer::url()` protocol-relative URL bypass** — URLs starting with `//` (e.g. `//attacker.com/phish`) bypassed the scheme check since they have no explicit protocol. Now rejects protocol-relative URLs.
- **`Model::castToClass()` arbitrary class instantiation** — The fallback `new $class($value)` constructor call accepted any class name from `$casts`, allowing instantiation of arbitrary classes. Now restricted to `Fw\` and `App\` namespaces only; all other classes must provide a static `wrap()`, `from()`, or `fromTrusted()` factory method.

### Fixed (Correctness)

- **`ViewCache` race condition on temp files** — `uniqid()` does not guarantee unique filenames across concurrent processes. Two processes could write to the same temp file, causing corrupt cache entries. Replaced with `tempnam()` which uses the OS to guarantee uniqueness.
- **`Env::castValue()` integer overflow** — Large numeric strings (e.g. `9999999999999999999`) were cast to `(int)`, silently overflowing to `PHP_INT_MAX`. Now compares the string representation of the cast result and keeps the original string if they differ.
- **`Blueprint::foreignId()` missing index** — Foreign key columns created by `foreignId()` did not have an index, causing slow JOIN performance on `belongsTo`/`hasMany` relationships. Now automatically creates an index on the column.
- **`Model::resetStrictMode()` worker-mode leak** — The static `$globalStrictMode` flag persisted across requests in FrankenPHP worker mode. Added `resetStrictMode()` and wired it into `HttpKernel::resetState()`.
- **`Model` N+1 lazy-loading detection** — Lazy-loaded relationships in debug mode now emit an `error_log()` warning with the model class and relationship name, helping developers identify N+1 query patterns early. Use `->with('relation')` to eager-load and suppress the warning.

## [2.1.7] - 2026-03-07

### Fixed (Security)

- **`View::resolvePath()` path traversal** — View names containing forward slashes (e.g. `../../etc/passwd`) were not validated against the base path, allowing arbitrary file disclosure. The method now resolves the real path with `realpath()` and verifies it stays within the views directory. Throws `RuntimeException` on traversal attempts.
- **`SpaAuthMiddleware` origin bypass when headers missing** — When both `Origin` and `Referer` headers were absent, the middleware returned `true` (allow), enabling attackers to bypass origin validation entirely. Now rejects requests without origin headers in production. Development mode only allows localhost origins.
- **`SpaAuthMiddleware` debug mode open-origin bypass** — In development/debug mode with no `spa_domains` configured, the middleware allowed ALL cross-origin credentialed requests. Now restricts development mode to `localhost`/`127.0.0.1`/`::1` origins only.
- **`PasswordReset::findByToken()` timing attack** — Token lookup returned `null` immediately when no token was found, allowing attackers to enumerate valid reset tokens via response timing. Now performs a constant-time dummy hash comparison on the not-found path.
- **`EmailVerification::verify()` timing attack** — Same timing attack vulnerability as `PasswordReset`. Same fix applied.
- **`AsyncHttp` SSRF vulnerability** — No validation of target host allowed requests to internal services (`127.0.0.1`, cloud metadata `169.254.169.254`, private IP ranges). Added `validateHost()` which resolves hostnames and rejects private/internal IPs. Use `withoutSsrfProtection()` for trusted internal services.
- **`AsyncHttp` header injection** — HTTP headers were concatenated without CRLF validation, allowing header injection attacks. All header names and values are now validated against `\r\n` characters.
- **`Router::validateConstraint()` ReDoS bypass** — The blacklist-based validation missed nested patterns like `(?:a+)+b` that cause catastrophic backtracking. Replaced with a whitelist approach that rejects all parenthesized groups in route constraints. Character classes (`[a-z0-9]+`) and predefined constraints remain available.
- **`QueryBuilder::validateIdentifier()` SQL injection via function calls** — The regex allowing aggregate functions (`COUNT(*)`) was too permissive, accepting `COUNT(*) OR 1=1; --`. Tightened to only allow `*` or simple `column`/`table.column` expressions inside function parentheses.
- **`CorsMiddleware` origin matching case-sensitive** — Domain names are case-insensitive but origin comparison used strict `in_array()`. `EXAMPLE.COM` would bypass an `example.com` allowlist. Now uses case-insensitive comparison.
- **`CorsMiddleware` empty origin accepted** — Empty string origins passed `isOriginAllowed()` when wildcard was not configured. Now explicitly rejects empty origins.

### Fixed (Correctness)

- **`CorsMiddleware` CORS headers silently dropped** — `Response` is immutable (each `header()` call returns a new instance), but `setCorsHeaders()` discarded every return value, so CORS headers were never actually set. Refactored to `applyCorsHeaders()` which chains the immutable returns.
- **`AsyncHttp` missing `HttpResponse` class** — `parseResponse()` instantiated `new HttpResponse(...)` but the class did not exist in the codebase, causing a fatal error at runtime. Created `src/Async/HttpResponse.php` with `statusCode`, `headers`, `body` properties and `json()`, `isOk()`, `header()` helpers.
- **`AsyncHttp` undefined `$responseBuffer` variable** — The write-stream callback referenced `$responseBuffer` in its `use()` clause but the variable was never initialized. Removed the unused reference.
- **`Deferred` fiber resurrection crash** — `resolve()`/`reject()` called `$fiber->resume()` or `$fiber->throw()` without checking if the Fiber had already terminated, causing `FiberError` in worker mode when Fibers from previous requests lingered. Now checks `$fiber->isTerminated()` and wraps calls in try-catch.
- **`BelongsToMany::detach()` empty array SQL error** — Passing an empty `$ids` array produced `IN ()` which is a SQL syntax error. Now returns `0` immediately for empty arrays.
- **`Connection::transaction()` original exception lost on rollback failure** — If `rollBack()` itself threw, the original exception from the callback was lost. Now catches rollback failures, logs them, and always re-throws the original exception.
- **`Model` JSON cast silent null on invalid JSON** — `json_decode()` without `JSON_THROW_ON_ERROR` silently returned `null` for invalid JSON values in `array` and `json` casts. Now throws `JsonException` so corrupt data is surfaced immediately.
- **`PasswordReset` worker-mode static connection leak** — Static `$connection` persisted across requests under FrankenPHP. Added `resetConnection()` and wired it into `HttpKernel::resetState()`.
- **`Cache` missing `increment()` implementation** — The layered `Cache` class (L1/L2) did not implement the `increment()` method added to `CacheInterface` in v2.1.1, causing a fatal error when the full test suite or rate limiter loaded the class. Now delegates to L2 for atomicity and updates L1 to reflect the new value.

## [2.1.6] - 2026-03-07

### Fixed (Security)

- **`DatabaseDriver` constructor accepts arbitrary table names** — The `$table` constructor parameter was stored and interpolated into raw SQL in `pop()`, `size()`, and `clear()` without validation. The same regex guard that `createTableSql()` used was absent from the constructor, so any caller passing a config/env-derived table name could inject arbitrary SQL. The constructor now applies `preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', …)` and throws `InvalidArgumentException` on mismatch — identical to the guard already present on `createTableSql()`.

### Fixed (Correctness)

- **`View::renderView()` ob buffer leak in worker mode** — If a template threw an exception inside a `$cache(…)` fragment block, the inner `ob_start()` opened by `startCache()` was never cleaned. The outer `catch` only called `ob_end_clean()` once, leaving the outer render buffer open. Under FrankenPHP worker mode, unfinished buffers persist across requests and accumulate per exception. Fixed by recording `ob_get_level()` before the render and unwinding all buffers back to that baseline in the catch block.
- **`QueryBuilder::paginate()` division by zero on `$perPage = 0`** — Passing zero as the page size caused `ceil($total / 0)`, producing a PHP division-by-zero warning and an `INF` `last_page` value. Now throws `InvalidArgumentException` when `$perPage <= 0`.

## [2.1.5] - 2026-03-07

### Fixed (Security)

- **`Csrf::ensureSession()` silent failure breaks CSRF protection** — When PHP headers had already been sent (e.g. accidental output before middleware), `ensureSession()` logged a warning and returned silently. All subsequent `$_SESSION` writes were no-ops, so every CSRF token comparison returned false with no visible error. Now throws `RuntimeException` immediately, surfacing the real cause (premature output) rather than masking it as CSRF failures.

### Fixed (Correctness)

- **`Connection::commit()` / `rollBack()` transaction underflow** — Calling either method with no active transaction decremented `transactionLevel` to `-1`, bypassed the top-level `commit()`/`rollBack()` path, and issued a `RELEASE SAVEPOINT` or `ROLLBACK TO SAVEPOINT` against a non-existent transaction — causing a PDO exception with no clear explanation. Both methods now throw `LogicException` immediately if `transactionLevel <= 0`.
- **`Env` unhandled escape sequences in double-quoted values** — Double-quoted `.env` values with escape sequences (`\"`, `\\`, `\n`, `\r`, `\t`) were stored verbatim with the backslash intact. `DB_PASSWORD="pass\"word"` produced `pass\"word` instead of `pass"word`. Now processes the standard escape sequences in double-quoted strings, and `\'` in single-quoted strings.
- **`OpcacheCache::set()` ignores PSR-16 null-TTL semantics** — A null `$ttl` was collapsed to `$this->defaultTtl` via `null ?? $this->defaultTtl`, meaning `set($k, $v, null)` expired the item after the default TTL instead of storing it forever. The `get()` expiry check also treated `null` expires as `0`, instantly expiring any manually stored forever-items. Both fixed to match the behaviour corrected in `FileCache` in v2.1.1.

## [2.1.4] - 2026-03-07

### Fixed (Security)

- **`Auth::isHttps()` proxy-blind cookie `Secure` flag** — The private helper checked only `$_SERVER['HTTPS']` and `SERVER_PORT`, which are both absent on internal ports behind an SSL-terminating load balancer. The `remember_me` cookie was therefore set without the `Secure` flag on HTTPS sites that use a reverse proxy. Fixed by delegating to `Request::isSecure()` via `RequestContext` when a request context is available — that method already honours the configured trusted-proxy list and `HTTP_X_FORWARDED_PROTO`. A raw `$_SERVER` fallback is retained for CLI/queue contexts.

### Fixed (Correctness)

- **`BelongsToMany::eagerLoad()` unquoted SQL identifiers** — Table and column names were interpolated directly into the raw eager-load JOIN query. Reserved-word table names (e.g. `order`, `group`) would produce a SQL syntax error. All identifiers now passed through `Connection::quoteIdentifier()`.
- **`BelongsToMany::detach()` unquoted SQL identifiers** — Same issue in the `DELETE … IN (?)` query used when detaching a subset of related IDs.
- **`BelongsToMany::pluck()` unquoted SQL identifiers** — Same issue in the pivot `SELECT` query used by `pluck()`, `sync()`, and `toggle()`.
- **`Str::snake()` cache bound undercounted** — The 512-entry prune check used `count($snakeCache)` which counts top-level word keys, not total entries. Each word can be cached under multiple delimiters, so the actual entry count before pruning was `512 × number_of_delimiters`. Fixed by counting total leaf entries with `array_sum(array_map('count', …))`.

## [2.1.3] - 2026-03-07

### Fixed (Security)

- **`Str::ulid()` 50-bit entropy instead of 80** — The random component used `ord($bytes[$i % 10]) % 32`, which discards the upper 3 bits of each byte (50 bits usable from 10 bytes) and repeats bytes 0–5 for characters 10–15, making the last 6 chars fully determined by the first 4. The ULID spec requires 80 bits of randomness. Fixed by treating the 10 random bytes as an 80-bit big-endian stream and consuming 5 bits at a time.
- **`EmailVerification::verify()` reusable token** — Successful verification returned the email without deleting the token, so the same verification link could be used repeatedly within the 24-hour window. Token is now consumed immediately on success (single-use). Expired tokens are deleted via the same internal helper to avoid re-hashing.
- **`EmailVerification` double-quoted SQL identifiers** — All three raw queries used ANSI double-quoted table names (`"email_verifications"`), which break MySQL unless `ANSI_QUOTES` mode is enabled. Fixed to use unquoted identifiers (consistent with the same fix applied to `PasswordReset` in v2.1.1).
- **`EmailVerification::$connection` worker-mode static leak** — The static connection reference persisted across requests under FrankenPHP. Added `resetConnection()` and wired it into `HttpKernel::resetState()` alongside `Gate::flushCache()` and `ApiToken::resetConfig()`.

### Fixed (Correctness)

- **`Blueprint::default()` unescaped single quotes** — String default values were wrapped in single quotes without escaping internal quotes (`'$value'`). Any default containing a single quote (e.g. `->default("it's here")`) produced invalid SQL. Fixed with `str_replace("'", "''", $value)`.
- **`ViewCache::makeKey()` uses `serialize()`** — `serialize()` is banned by project security rules and non-deterministic across PHP versions for complex types. Replaced with `json_encode(JSON_THROW_ON_ERROR)`, which is deterministic and safe.

## [2.1.2] - 2026-03-07

### Fixed (Security)

- **`Response::securityHeaders()` CSP `unsafe-inline`** — Removed `'unsafe-inline'` from both `script-src` and `style-src`. The directive negated all XSS protection by allowing inline script/style execution.
- **`Auth::getUserFromRememberToken()` integer overflow** — Cookie `userId` was cast with `(int)` after a basic `is_numeric()` check which passes non-decimal strings like `1e300`. Now uses `ctype_digit()` + non-zero guard, with a constant-time dummy hash comparison on failure to prevent user enumeration.
- **`PersonalAccessToken` hidden token field** — Added `protected static array $hidden = ['token']` to prevent the SHA-256 token hash from appearing in API JSON responses (e.g. `tokens()` endpoints).
- **`Request::wantsJson()` URI prefix heuristic removed** — The previous implementation matched any URI starting with `/api` as "wanting JSON", catching `/api-docs`, `/apiary`, etc. Simplified to `expectsJson() || isAjax()`.
- **`RegisterController` user enumeration** — The "email already registered" error was returned verbatim, allowing attackers to enumerate valid email addresses. Response genericized to `'Registration failed. Please try again.'`.
- **`Env::castValue()` lax numeric parsing** — `is_numeric()` accepts scientific notation (`1e5`), hex (`0x1A`), and leading `+`. Replaced with strict `preg_match` anchored regex for integers and decimals only.
- **`Connection::quoteIdentifier()` silent passthrough on unknown driver** — The `default:` case returned a bare unquoted identifier instead of failing. Now throws `RuntimeException` to surface misconfiguration.
- **`ApiToken` prefix-independent hashing** — Token hash was computed from the full token string including prefix, so changing the configured prefix in `config/api.php` silently invalidated all existing tokens. Hash now computed from the prefix-free body only; `find()` strips prefix before hashing.
- **`AuthMiddleware::storeIntendedUrl()` uninitialized session** — `$_SESSION` was written before the session was started, causing a silent no-op when the route did not include the `web`/`csrf` middleware group. Now calls `$this->app->initSession()` first.

### Fixed (Correctness)

- **`ViewCache::makeKey()` stale cache after deployment** — Cache key was derived from view name and data only, so updated templates were served stale until the cache TTL expired. Now includes `filemtime()` of the template file so any change immediately busts the entry.
- **`FileCache::set()` PSR-16 null TTL semantics** — A `null` TTL was treated as "use the default TTL" instead of the PSR-16 spec of "store indefinitely". Now stores `'expires' => null` (no expiry).
- **`FileCache::increment()` TOCTOU race** — Locking the data file directly suffered a TOCTOU: if another process deleted the file between `fopen()` and `flock()`, writes went to a ghost inode never accessible by name. Fixed with a dedicated sidecar `.lock` file on a stable inode.
- **`HttpKernel::normalizeCacheUri()` array param collapse** — Query string normalization used `parse_str` + `http_build_query`, which collapsed array parameters (`?ids[]=1&ids[]=2` → `?ids%5B0%5D=1`) causing different URLs to share cache keys. Now sorts raw `key=value` pairs from the unparsed query string.
- **`Config::saveCache()` directory race** — `mkdir()` could fail if two processes bootstrapped concurrently and one created the directory first. Changed to three-phase: `!is_dir() && !@mkdir() && !is_dir()` pattern.
- **`LoginController::login()` null guard** — `Auth::attempt()` returning `true` and then `Auth::user()` returning `null` is theoretically possible under Fiber scheduling. Added a null guard returning 401.
- **`Container::getReflectionCache()` infinite spin** — The Fiber spin loop waiting for another context to complete reflection had no timeout. If the initialising Fiber crashed, all subsequent callers would spin forever. Now throws `RuntimeException` after 1000 iterations.
- **`Container::call()` unvalidated array callback** — `[$class, $method] = $callback` assumed the array had exactly 2 elements. A 1-element array caused an `ArrayError`. Now validates count and method name type before destructuring.
- **`Dashboard.vue` missing error state in stat cards** — On API failure the spinner stopped but stat cards showed `0`, indistinguishable from real data. Cards now display `"Unavailable"` in the error path via a `loadError` ref.
- **`frontend/src/main.ts` stale token not detected** — The Vue Router guard only checked if a token existed in `localStorage`, not whether the server still considered it valid (it may have been revoked). Added a global Axios 401 interceptor that clears `localStorage` and redirects to `/login` on any 401 response, ensuring server-side revocation is respected immediately.

## [2.1.1] - 2026-03-07

### Fixed (Security)

- **`Auth::logout()` order bug** — `clearRememberToken()` was called after the user was removed from `RequestContext`, causing a silent no-op when a remember cookie was present. The remember token is now cleared before the context is torn down.
- **`PasswordReset` SQL syntax** — All four SQL queries used ANSI double-quoted identifiers (`"password_resets"`) which break MySQL without `ANSI_QUOTES` mode. Fixed to use unquoted identifiers which work across MySQL, SQLite, and PostgreSQL.
- **`LoginController::logout()` token revocation** — Logout now revokes the Bearer token used for the request so it cannot be replayed after the user logs out.
- **`LoginController::confirmPassword()` null crash** — `Auth::user()` could return `null` on unauthenticated calls, causing a fatal error on `$user->password`. Added a null guard that returns 401.
- **`config/routes.php` logout route missing middleware** — `/api/auth/logout` was not protected by `auth:api`, allowing unauthenticated requests to hit it. Fixed.
- **`DatabaseDriver::createTableSql()` SQL injection** — The `$table` parameter was interpolated directly into a raw SQL string. Added a strict alphanumeric/underscore validation guard.
- **`Model::toArray()` / `jsonSerialize()` leaking hidden fields** — The base model had no `$hidden` support, causing fields like `password` to appear in JSON responses. Added `protected static array $hidden = []` and filtering in `toArray()`.
- **`RateLimitMiddleware` non-atomic increment** — The read-then-write pattern allowed concurrent requests to bypass rate limits. Replaced with a single `cache->increment()` call.
- **`CacheInterface` missing `increment()` contract** — Added `increment(string $key, int $step = 1, ?int $ttl = null): int|false` to the interface. Implemented atomically in `ApcuCache` (via `apcu_inc`) and `FileCache` (via `LOCK_EX`). `MemoryCache`, `TaggedCache`, and `OpcacheCache` also implement the new method.
- **`Router::url()` unencoded parameters** — Values substituted into named route URLs were not percent-encoded. Fixed with `rawurlencode()`.
- **`Router::validateConstraint()` empty alternation check** — The ReDoS alternation detection block had an empty body and silently passed dangerous patterns. Now throws `InvalidArgumentException`.
- **`Request::expectsJson()` false positive on `*/*`** — Browsers send `Accept: text/html,*/*` which previously matched and routed HTML requests through the JSON error handler. Fixed to only treat `*/*` as JSON when `text/html` is absent.
- **`CorsMiddleware` hardcoded config** — CORS settings were hardcoded in the constructor. Now merged from `$app->config('cors', [])`, allowing per-project overrides via `config/app.php`.
- **`Gate::$policyCache` worker-mode leak** — The static policy cache persisted across requests under FrankenPHP. `Gate::flushCache()` is now called from `HttpKernel::resetState()`.
- **`ApiToken::$config` worker-mode leak** — The static config cache persisted across requests. Added `ApiToken::resetConfig()` and wired it into `HttpKernel::resetState()`.
- **`ApiToken::pruneExpired()` N+1 queries** — Replaced a fetch-all-then-delete-each loop with a single bulk `DELETE` query.
- **`RegisterController` TOCTOU race** — A duplicate email could slip through between the `unique:users` validation check and the `INSERT`. Now catches `PDOException` with error code 1062 (MySQL) / `UNIQUE` (SQLite) and returns a 422 with a user-friendly message.
- **`DatabaseDriver` attempt loop off-by-one** — The `for ($i = 0; $i <= $row['attempts']; $i++)` loop count now correctly matches the DB-stored attempt count.
- **`StatsController` hardcoded benchmark** — Removed the hardcoded `'requestsPerSecond' => '40,058'` value that was leaking internal benchmark data in API responses.
- **`MainLayout.vue` `provide()` after `await`** — Calling `provide('userName', ...)` inside an `async` function (after `await`) is a Vue 3 no-op. Moved to a synchronous `computed` ref provided at setup time.
- **`PersonalAccessToken` model restored** — The model was missing from `app/Models/` (deleted during 2.0 branch work). Restored so `LoginController` and `ApiToken` can reference it.

## [2.1.0] - 2026-03-06

### Added

- **`make:spa` command** — Scaffolds a complete Vue 3 + TypeScript + Vite SPA with a PHP API backend. Automatically runs `npm install` and `npm run build` after scaffolding. Generates a clean 4-page starter (Home, Login, Register, Dashboard) using VibeUI components.
- **`Fw\Support\Hash`** — New class providing `Hash::make()` and `Hash::check()` for bcrypt password hashing.
- **`Fw\Auth\EmailVerification`** — Foundation for email confirmation flows (opt-in).
- **Migration `0007_create_email_verifications_table`** — Supports email verification tokens.
- **API Controllers** — `Api/Auth/LoginController`, `Api/Auth/RegisterController`, `Api/StatsController` added to starter app.
- **`DevCommand`** — `php fw dev` for streamlined development workflow.
- **`SyncEnvCommand`** — `php fw env:sync` to sync `.env.example` with `.env`.
- **`SecurityCheckCommand`** — `php fw security:check` focused security scan.
- **Queue `allowClasses()` method** — Both `FileDriver` and `DatabaseDriver` now expose `allowClasses(array $classes)` to restrict which PHP classes may be instantiated during job deserialization (defense-in-depth over the default HMAC-verified `allowed_classes: true`).

### Changed

- **`Sanitizer` rewrite** — `Fw\Security\Sanitizer` completely rewritten:
  - `json()` uses `json_decode` + `json_last_error()` instead of try/catch theater
  - `stripTags()` no longer accepts `$allowedTags` — strip_tags with an allowlist is insecure (passes through tag attributes unchanged)
  - `collapseSpaces()` renamed from `trim()` — the method normalizes all whitespace, not just trims edges
  - `int()` simplified — removed redundant `preg_match` step
  - `float()` regex anchored to prevent a minus sign appearing mid-value
- **`Str` static cache bounds** — `$studlyCache`, `$camelCache`, `$snakeCache` capped at 512 entries each; oldest 256 evicted when cap is reached. Prevents unbounded memory growth in FrankenPHP worker mode.
- **`QueryBuilder` SELECT quoting** — Column identifiers in `SELECT` clauses are now passed through `quoteIdentifier()`. Raw expressions (function calls, `AS` aliases) are detected and passed through unmodified.
- **SPA simplified** — Starter SPA reduced to 4 essential pages (Home, Login, Register, Dashboard). Premature pages (ForgotPassword, ResetPassword, VerifyEmail, Profile, ApiTokens) removed for a cleaner first-run experience.
- **npm dependencies** — `@vitejs/plugin-vue` bumped to `^6.0.4` (Vite 7 compatibility); `pinia` bumped to `^3.0.4` (Vue Router 5 compatibility).
- **CLI version** — `php fw` now correctly reports `2.1.0`.

### Fixed

- **Fatal boot error** — `config/routes.php` referenced deleted controllers via `use` statements; rewrote starter routes.
- **Route middleware syntax** — `make:spa`-generated routes incorrectly passed middleware as the 3rd positional argument (`?string $name`) instead of calling `->middleware()`.
- **Vue Router guard** — Updated `router.beforeEach` to return-value style; Vue Router 5 deprecated the `next()` callback.
- **MainLayout crash** — `Cannot read properties of undefined (reading 'charAt')` when `user.name` is null; optional chaining and fallbacks added.
- **User model** — Added `findByEmail()` and `verifyPassword()` methods required by `Auth::attempt()`.
- **Missing `tsconfig.json`** — Created for the SPA frontend; `vue-tsc` exited without it.
- **VibeUI component names** — All SPA stubs corrected to use globally-registered component names (`VibeButton`, `VibeFormInput`, `VibeFormGroup`, `VibeAlert`, `VibeModal`). Removed non-existent imports.

### Security

- **Auth cookies HTTPS-aware** — `remember_me` cookies are now set with `Secure: true` automatically when the request is served over HTTPS. Previously hardcoded to `false`.

---

## [2.0.0] - 2025-12 (The Concurrency Update)

See [RELEASE-NOTES.md](RELEASE-NOTES.md) for the full 2.0 changelog and upgrade guide from v1.

### Highlights

- Fiber-based request handling with `EventLoop`
- `RequestContext` for leak-free worker mode (FrankenPHP, RoadRunner)
- Immutable `QueryBuilder`
- 40,000+ req/sec on Apple M2 with FrankenPHP worker mode
- Breaking: `HttpKernel::handle()` now returns a `Response`; emit with `SapiEmitter`

---

## [1.0.0] - 2026-01-28

### Added

- **Core Framework**
  - Application container with dependency injection
  - Router with named routes, groups, and middleware
  - Request/Response handling with streaming support
  - View engine with layouts, sections, and caching
  - Configuration management with caching

- **Database**
  - PDO-based Connection with transaction support
  - Fluent QueryBuilder with operator whitelisting
  - Active Record Model with relationships
  - Migration system with rollback support
  - N+1 query detection in development

- **Security**
  - CSRF protection with timing-safe validation
  - Input sanitization (HTML, URL, filename, JSON)
  - Mass assignment protection with strict mode
  - Rate limiting middleware
  - Security headers helper (HSTS, CSP, etc.)
  - HMAC-signed queue job payloads

- **Authentication**
  - Session-based authentication
  - Remember me tokens with HMAC signing
  - API token management
  - Timing attack mitigations

- **Queue System**
  - File and database drivers
  - Delayed jobs
  - Retry with backoff
  - Failed job handling

- **CLI Framework**
  - Code generators (model, controller, middleware, migration)
  - Database commands (migrate, rollback, seed)
  - Development server
  - Route listing

- **Validation**
  - PHP 8 attribute-based validation
  - Form request classes
  - Comprehensive rule set

- **Async Support**
  - Fiber-based request handling
  - Event loop integration
  - Worker mode compatibility (FrankenPHP, RoadRunner)

### Security

- Parameterized queries prevent SQL injection
- Operator whitelisting in QueryBuilder
- CSRF tokens with timing-safe comparison
- Unicode control character filtering in URL sanitizer
- Signed serialization prevents RCE in queue jobs
- Constant-time authentication comparisons
- HMAC-signed remember cookies
- Secure session configuration by default

### Performance

- 15,500+ req/sec with FrankenPHP worker mode
- OPcache-based configuration caching
- Route caching
- View fragment caching
- Reflection metadata caching
- Lazy service loading

---

## Version History

### Versioning Policy

- **2.x**: Fiber-safe, worker-mode architecture, PHP 8.4+
- **1.x**: Initial stable release, PHP 8.4+
- **Future**: PHP version requirements may increase in major versions

### Upgrade Guides

Upgrade guides will be provided for major version changes.

---

[Unreleased]: https://github.com/velkymx/vibefw/compare/v2.1.11...HEAD
[2.1.11]: https://github.com/velkymx/vibefw/compare/v2.1.10...v2.1.11
[2.1.10]: https://github.com/velkymx/vibefw/compare/v2.1.9...v2.1.10
[2.1.9]: https://github.com/velkymx/vibefw/compare/v2.1.8...v2.1.9
[2.1.8]: https://github.com/velkymx/vibefw/compare/v2.1.7...v2.1.8
[2.1.7]: https://github.com/velkymx/vibefw/compare/v2.1.6...v2.1.7
[2.1.6]: https://github.com/velkymx/vibefw/compare/v2.1.5...v2.1.6
[2.1.5]: https://github.com/velkymx/vibefw/compare/v2.1.4...v2.1.5
[2.1.4]: https://github.com/velkymx/vibefw/compare/v2.1.3...v2.1.4
[2.1.3]: https://github.com/velkymx/vibefw/compare/v2.1.2...v2.1.3
[2.1.2]: https://github.com/velkymx/vibefw/compare/v2.1.1...v2.1.2
[2.1.1]: https://github.com/velkymx/vibefw/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/velkymx/vibefw/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/velkymx/vibefw/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/velkymx/vibefw/releases/tag/v1.0.0
