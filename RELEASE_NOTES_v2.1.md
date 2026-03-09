# VibeFW v2.1 Release Notes

**Release Date:** March 8, 2026

---

## One Command, Full Stack

VibeFW v2.1 is the release where the framework becomes a complete platform. Run `php fw make:spa` and in under 60 seconds you have a production-ready full-stack application: a PHP 8.4 API backend with Bearer token auth, a Vue 3 + TypeScript frontend with VibeUI components, dark mode, route guards, and validation — all wired together and ready to build on.

---

## Headline Features

### `make:spa` — Full-Stack Scaffold

```bash
php fw make:spa
```

One command generates:

- **PHP API** — Login, register, logout, user profile, password management, API token CRUD, and dashboard stats endpoints
- **Vue 3 SPA** — Home page, auth forms, protected dashboard with sidebar layout, dark mode toggle, 404 page
- **VibeUI 0.7+** — Every component globally registered. Forms, buttons, cards, modals, data tables, icons — just use them
- **Auth wired end-to-end** — Bearer tokens, localStorage, route guards, global 401 interceptor, server-side validation mapped to form fields
- **TypeScript types** — Generated `api.ts` with interfaces for every API response
- **Tests included** — Vitest unit test + Playwright e2e test scaffolded
- **Documentation generated** — `frontend/README.md` with tech stack, project structure, auth flow, API reference, and troubleshooting

### Framework / Application Layer Fully Decoupled

The entire `src/` directory is now free of `App\` namespace imports. The framework no longer assumes your models live in `App\Models`:

```php
// Configure once in your service provider
Auth::setUserModel(App\Models\User::class);
Gate::setPolicyNamespace('App\\Policies');
ApiToken::setTokenModel(App\Models\PersonalAccessToken::class);
```

This means VibeFW can be used as a library in any project structure — not just the default scaffold.

### PHPStan Level 8

All new code is checked at PHPStan level 8 (the strictest). A baseline covers 641 pre-existing type annotations. The CI pipeline enforces this on every commit.

---

## Security Hardening

v2.1 includes **30+ security fixes** discovered through systematic code review:

### Critical

- **Container Fiber instance leak** — `spl_object_id` reuse allowed auth state to leak between requests in worker mode. Fixed with `WeakMap`.
- **Auth remember token replay** — Tokens weren't rotated on use, allowing 30-day replay. Now single-use with rotation.
- **View path traversal** — View names like `../../etc/passwd` could read arbitrary files. Now validated against the views directory.
- **SSRF in AsyncHttp** — No host validation allowed requests to internal services and cloud metadata endpoints. Now blocks private/internal IPs.
- **QueryBuilder SQL injection** — Two separate injection vectors in `validateIdentifier()` via alias expressions and function calls. Both tightened.
- **CSP `unsafe-inline` in security headers** — Negated all XSS protection. Removed.

### High

- **CSRF bypass on premature output** — `ensureSession()` silently failed when headers were already sent, making all CSRF checks pass. Now throws immediately.
- **SPA auth middleware origin bypass** — Missing Origin + Referer headers were accepted. Debug mode allowed all origins. Both fixed.
- **DatabaseDriver SQL injection** — Constructor accepted arbitrary table names interpolated into raw SQL. Now validated.
- **Blueprint foreign key action injection** — `onDelete()`/`onUpdate()` accepted arbitrary strings. Now whitelisted.
- **Auth middleware redirect allows `javascript:` scheme** — URL scheme not validated. Now whitelists http/https only.
- **Str::ulid() only 50-bit entropy** — Should be 80-bit per spec. Fixed bit extraction.

### Medium

- **PasswordReset / EmailVerification timing attacks** — Token enumeration via response timing. Constant-time comparison on all paths.
- **Validator ReDoS** — Unicode regex without input length guards. 64KB cap added.
- **CORS case-sensitive origin matching** — `EXAMPLE.COM` bypassed `example.com` allowlist. Case-insensitive now.
- **Model::castToClass() arbitrary instantiation** — Restricted to `Fw\` and `App\` namespaces.
- **Storage directories world-readable** — Cache, logs, queue changed from 0755 to 0750.
- **OpcacheCache increment race condition** — Non-atomic read-then-write. Now uses lock files.
- **Request wildcard trusted proxies warning** — `['*']` allows IP spoofing. Now logs production warning.
- **BelongsToMany loose comparison** — `in_array()` without strict flag. Fixed.
- **ApiToken prefix-independent hashing** — Changing prefix invalidated all tokens. Now hashes body only.

---

## Correctness Fixes

v2.1 includes **40+ correctness fixes**:

- **Deferred::race() fundamentally broken** — Polling-based approach never settled if no deferred resolved on first tick. Replaced with listener mechanism.
- **Collection::first()/last() wrong for falsy values** — `0`, `""`, `false` treated as missing. Fixed.
- **FileCache::gc() deletes forever-cached entries** — `null < time()` evaluates true in PHP 8. Explicit null check added.
- **Env escape sequences not processed** — `\"`, `\\`, `\n`, `\r`, `\t` stored verbatim in double-quoted values. Now parsed.
- **Transaction commit/rollback underflow** — No active transaction decremented to -1. Now throws LogicException.
- **Connection::transaction() original exception lost** — Rollback failure swallowed the real error. Now always re-throws original.
- **Model JSON cast returns null instead of []** — Only `'array'` cast handled null-to-empty. `'json'` cast now included.
- **DateTime::equals() identity vs value comparison** — Two equivalent DateTimeImmutable objects compared unequal. Fixed.
- **QueryBuilder paginate division by zero** — `$perPage = 0` produced `INF`. Now throws.
- **Container reflection cache infinite spin** — Crashed Fiber caused infinite loop. Timeout after 1000 iterations.
- **CorsMiddleware headers silently dropped** — Immutable Response returns discarded. Chained properly now.
- **Env integer overflow** — `9999999999999999999` silently truncated to `PHP_INT_MAX`. Now preserved as string.
- **View ob_buffer leak in worker mode** — Exceptions inside cache fragments left orphaned buffers. Full baseline unwind added.

---

## Architecture Improvements

- **Framework/app boundary enforced** — Architecture tests verify `src/` never imports from `app/`. Gate, Auth, ApiToken, TokenGuard all use configurable class references.
- **Autoload fixed** — `App\` namespace moved from `autoload-dev` to `autoload`. Production `--no-dev` installs now work.
- **PersonalAccessToken model complete** — Added `isValid()`, `cannot()`, `touchLastUsed()`, `revoke()`, hierarchical `can()`, `user()` relationship, UUID-compatible key type.
- **Worker-mode state resets** — `Gate::flushCache()`, `ApiToken::resetConfig()`, `PasswordReset::resetConnection()`, `EmailVerification::resetConnection()`, `Model::resetStrictMode()` all wired into `HttpKernel::resetState()`.

---

## Documentation

- **SPA Quick Start Guide** — End-to-end walkthrough from `git clone` to production deployment
- **Frontend README** — Generated in every scaffold with tech stack, auth flow, API reference, VibeUI cheat sheet
- **TypeScript API types** — Generated interfaces for all API responses
- **Inline comments** — Every SPA stub extensively documented with API contracts, auth flow explanations, and extension guides
- **AI context files** — `llms.txt` (backend) and `llms-ui.txt` (frontend) cross-referenced with canonical doc links
- **VibeUI AI Blueprint** — Full component reference with VibeIcon docs, dark mode rules, validation patterns

---

## By the Numbers

| Metric | Count |
|--------|-------|
| Security fixes | 30+ |
| Correctness fixes | 40+ |
| Architecture improvements | 10+ |
| Documentation additions | 6 new files |
| Commits since 2.1.0 | 55 |
| PHPStan level | 8 (strictest) |
| Performance | 40,058 req/s |
| Memory leaks after 1.2M requests | 0 |

---

## Upgrade Guide

v2.1 is fully backward compatible with v2.0. No breaking changes.

```bash
composer update velkymx/vibefw
php fw migrate
php fw optimize
```

To use the new SPA scaffold on an existing project:

```bash
php fw make:spa
```

If you were previously importing `App\Models\User` in framework-level code, the framework now handles this internally via configurable model classes. No action needed unless you customized the Auth layer.

---

## What's Next

VibeFW v2.1 is the foundation. The framework is fast, secure, documented, and complete. Build something great with it.

---

*Full changelog: [CHANGELOG.md](CHANGELOG.md)*
