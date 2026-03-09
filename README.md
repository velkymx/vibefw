# VibeFW v2.1

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.4-8892BF.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-passing-brightgreen.svg)]()

A high-performance, security-focused PHP 8.4+ MVC framework built for modern, high-concurrency web development.

## v2.1: SPA Scaffold + Security Hardening

VibeFW v2.1 adds first-class SPA tooling, security improvements, and correctness fixes across the framework.

### What's New in 2.1
- **`make:spa` command** — Scaffold a production-ready Vue 3 + TypeScript + Vite SPA in seconds. Generates a full-stack starter (PHP API + Vue frontend) with auth, routing, and VibeUI components wired up. Runs `npm install` and `npm run build` automatically.
- **HTTPS-aware auth cookies** — Remember-me cookies now set `Secure: true` automatically in production.
- **QueryBuilder SELECT quoting** — Column identifiers in SELECT clauses are now properly quoted.
- **Worker-mode safe `Str` caches** — Static conversion caches are now bounded at 512 entries.

See [CHANGELOG.md](CHANGELOG.md) for the full list of changes.

## v2.0: The Concurrency Update

VibeFW v2.0 is a ground-up architectural refactor designed for persistent runtimes like **FrankenPHP Worker Mode**. It introduces true request isolation and massive performance gains.

### Key v2.0 Features
- **40,000+ Requests/Sec** - Optimized for high-concurrency worker environments.
- **Fiber-Isolated State** - Automatic request isolation using Fiber-local container singletons and `WeakMap` context.
- **Zero-Side-Effect Response** - `Response` is now a pure value object, decoupled from PHP's output buffer.
- **Strictly Immutable QueryBuilder** - Prevents silent query contamination across branching logic.
- **Bulletproof Memory Management** - Automatic state resetting and container flushing prevents leaks in long-running processes.

## Features

- **Blazing Fast** - 40,000+ requests/sec with FrankenPHP worker mode.
- **Security First** - Built-in protection against CSRF, XSS, SQL injection, and timing attacks.
- **True Async I/O** - Non-blocking HTTP and Database execution using PHP Fibers and EventLoop.
- **Result/Option Types** - Null-safe, exception-free error handling.
- **Active Record ORM** - Elegant, immutable database interactions with mass assignment protection.
- **CQRS & Events** - Command/Query separation and event-driven architecture built-in.
- **Modern PHP** - Leveraging property hooks, asymmetric visibility, and readonly properties.

## Requirements

- PHP 8.4+
- Composer
- SQLite, MySQL, or PostgreSQL

## Quick Start

### Create a New Project

```bash
composer create-project velkymx/vibefw my-app
cd my-app
```

That's it. Composer automatically:
- Creates `.env` with secure keys
- Sets up storage directories
- Creates the SQLite database
- Runs all migrations

### Add a Vue 3 Frontend (Optional)

```bash
php fw make:spa
```

Generates a complete SPA with auth, routing, and VibeUI components. See the [SPA Quick Start](docs/spa-quick-start.md) for details.

### Manual Setup (for cloned repos)

```bash
git clone https://github.com/velkymx/vibefw.git my-app
cd my-app
composer install
php fw setup
```

### Run in High-Performance Worker Mode (Recommended)

VibeFW is designed to run at peak performance using FrankenPHP:

```bash
./frankenphp php-server --listen :8080 --worker public/index.php
```

## CLI Commands

```bash
php fw                              # List all commands

# Project Setup
php fw setup                        # Initialize project (env, keys, database)
php fw make:spa                     # Scaffold Vue 3 + TypeScript SPA

# Code Generation
php fw make:model Post -m           # Model + migration
php fw make:controller PostController -r  # Resource controller
php fw make:migration create_posts_table

# Database
php fw migrate                      # Run pending migrations
php fw migrate:fresh                # Drop all & re-migrate

# Development
php fw serve --port=8080            # Start standard dev server
php fw routes:list                  # List all routes
php fw security:check               # Security scan
```

### Full-Stack in Two Commands

```bash
composer create-project velkymx/vibefw my-app
cd my-app && php fw make:spa
```

The SPA scaffold generates a complete Vue 3 + TypeScript + VibeUI frontend with:
- **Home** — Public landing page with feature cards
- **Login / Register** — Auth forms with validation, wired to the PHP API
- **Dashboard** — Protected stats page inside a sidebar + topbar shell
- **Dark mode** — Toggle with Bootstrap CSS variable support
- **TypeScript types** — Interfaces for all API responses

## Documentation

- [**SPA Quick Start**](docs/spa-quick-start.md) — Build a full-stack app in 5 minutes
- [**Changelog**](CHANGELOG.md)
- [**Upgrading to v2.0.0**](docs/UPGRADING-1.0.5-to-2.0.0.md)
- [Controllers](docs/controllers.md)
- [Models](docs/models.md)
- [Views](docs/views.md)
- [Routing](docs/routing.md)
- [Middleware](docs/middleware.md)
- [Result & Option Types](docs/result-option.md)
- [Database & Migrations](docs/database.md)
- [VibeUI Components](https://github.com/velkymx/vibeui/blob/main/docs/README.md) — Full component reference
- [VibeUI AI Blueprint](docs/VIBE-UI-AI.md) — AI agent integration guide

## Performance

Benchmarked with FrankenPHP worker mode on Apple M2 (MacBook Air):

| Metric | Result |
| :--- | :--- |
| **Requests per Second** | **40,058.37** |
| **Average Latency** | **5.15ms** |
| **Total Requests (30s)** | **1,202,023** |
| **Memory Stability** | **Perfect** (Zero leaks after 1.2M requests) |

## Core Concepts

### Immutable Query Building

The QueryBuilder is now immutable. Chaining works normally, but conditional steps require re-assignment:

```php
$query = User::where('active', true);

if ($adminOnly) {
    $query = $query->where('role', 'admin'); // Capture the new instance
}

$users = $query->orderBy('name')->get();
```

### Side-Effect Free Responses

Controllers return `Response` objects which are emitted by the kernel. No more global `header()` calls or `exit()`.

```php
public function index(Request $request): Response
{
    return $this->view('welcome')
        ->header('X-Framework', 'VibeFW')
        ->cache(3600);
}
```

## Testing

```bash
composer test      # Runs all 920+ tests
composer ci        # Full pipeline (Lint, Static Analysis, Tests)
```

## License

MIT License. See [LICENSE](LICENSE) for details.
