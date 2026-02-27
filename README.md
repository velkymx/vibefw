# VibeFW

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.4-8892BF.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-passing-brightgreen.svg)]()

A high-performance, security-focused PHP 8.4+ MVC framework built for modern web development.

## Features

- **Blazing Fast** - 15,500+ requests/sec with FrankenPHP worker mode
- **Security First** - Built-in protection against CSRF, XSS, SQL injection, timing attacks
- **Fiber-Based Async** - Non-blocking I/O with PHP Fibers
- **Result/Option Types** - Null-safe, exception-free error handling
- **Active Record ORM** - Elegant database interactions with mass assignment protection
- **CQRS Support** - Command/Query separation built-in
- **Modern PHP** - Property hooks, asymmetric visibility, readonly classes
- **Zero Dependencies** - Core framework has no external dependencies

## Requirements

- PHP 8.4+
- Composer
- SQLite, MySQL, or PostgreSQL

## Quick Start

### Install as a Library

```bash
composer require velkymx/vibefw
```

### Install as a Project

```bash
# Clone and install
git clone https://github.com/velkymx/vibefw.git
cd vibefw
composer install

# Configure environment
cp .env.example .env

# Run migrations
php fw migrate

# Start development server
php fw serve
```

Visit `http://localhost:8000` to see your app.

## CLI Commands

VibeFW includes a powerful CLI for development tasks:

```bash
php fw                              # List all commands

# Code Generation
php fw make:model Post -m           # Model + migration
php fw make:controller PostController -r  # Resource controller
php fw make:migration create_posts_table
php fw make:middleware RateLimitMiddleware

# Database
php fw migrate                      # Run pending migrations
php fw migrate:status               # Show migration status
php fw migrate:rollback             # Rollback last batch
php fw migrate:fresh                # Drop all & re-migrate

# Development
php fw serve --port=8080            # Start dev server
php fw routes:list                  # List all routes
php fw cache:clear                  # Clear caches

# Security
php fw validate:security            # Scan for vulnerabilities
```

## Directory Structure

```
app/
├── Controllers/      # HTTP request handlers
├── Models/           # Database models
├── Views/            # PHP templates
│   └── layouts/      # Layout templates
└── Providers/        # Service providers

config/
├── app.php           # Application settings
├── database.php      # Database configuration
├── routes.php        # Route definitions
├── middleware.php    # Middleware configuration
└── providers.php     # Service provider registration

database/
└── migrations/       # Database migrations

public/
└── index.php         # Application entry point

src/                  # Framework core
├── Console/          # CLI framework & commands
├── Core/             # Application, Router, Container
├── Database/         # ORM, QueryBuilder, Migrations
├── Model/            # Active Record base
└── ...

stubs/                # Code generation templates
fw                    # CLI entry point
```

## Documentation

- [Controllers](docs/controllers.md)
- [Models](docs/models.md)
- [Views](docs/views.md)
- [Routing](docs/routing.md)
- [Middleware](docs/middleware.md)
- [Validation](docs/validation.md)
- [Result & Option Types](docs/result-option.md)
- [Database & Migrations](docs/database.md)
- [Service Providers](docs/providers.md)
- [CQRS](docs/cqrs.md)
- [Authentication](docs/authentication.md)
- [Caching](docs/caching.md)

## Core Concepts

### No Null, No Exceptions

FW uses `Result` and `Option` types instead of null and exceptions:

```php
// Instead of returning null
User::find($id)->match(
    some: fn($user) => $user->name,
    none: fn() => 'Guest'
);

// Instead of try/catch
$result = $this->createUser($data);
if ($result->isOk()) {
    return $this->redirect('/users/' . $result->getValue()->id);
}
return $this->view('users.create', ['errors' => $result->getError()]);
```

### Simple Routing

```php
// config/routes.php
return function (Router $router): void {
    $router->get('/', [HomeController::class, 'index']);
    $router->get('/posts/{id}', [PostController::class, 'show']);

    $router->group('/admin', function (Router $router) {
        $router->get('/dashboard', [AdminController::class, 'dashboard']);
    }, ['auth']);
};
```

### Elegant Models

```php
class Post extends Model
{
    protected static ?string $table = 'posts';
    protected static array $fillable = ['title', 'content', 'user_id'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

// Usage
$posts = Post::where('published', true)->orderBy('created_at', 'desc')->get();
```

### Clean Controllers

```php
class PostController extends Controller
{
    public function store(Request $request): Response
    {
        $validation = $this->validate($request, [
            'title' => 'required|min:3',
            'content' => 'required|min:10',
        ]);

        if ($validation->isErr()) {
            return $this->view('posts.create', ['errors' => $validation->getError()]);
        }

        $post = Post::create($validation->getValue());
        return $this->redirect('/posts/' . $post->id);
    }
}
```

## Performance

Benchmarked with FrankenPHP worker mode on Apple M3 Pro:

| Endpoint | Requests/sec | Avg Latency | P99 Latency |
|----------|-------------|-------------|-------------|
| /health | 15,530 | 12.94ms | 18.2ms |
| /api/users | 8,240 | 24.31ms | 35.1ms |
| /dashboard | 5,120 | 39.06ms | 52.3ms |

## Security

VibeFW includes comprehensive security features:

- **CSRF Protection** - Automatic token validation with timing-safe comparison
- **SQL Injection Prevention** - Parameterized queries and operator whitelisting
- **XSS Prevention** - Auto-escaping in views, input sanitization
- **Mass Assignment Protection** - Fillable/guarded attributes with strict mode
- **Timing Attack Mitigation** - Constant-time comparison for authentication
- **Serialization Security** - HMAC-signed queue payloads prevent RCE
- **Rate Limiting** - Built-in request throttling with cache backend
- **Trusted Proxy Support** - Secure X-Forwarded-* header handling

See [SECURITY.md](SECURITY.md) for our security policy.

## Testing

```bash
# Run all tests
composer test

# Run with coverage
composer test:coverage

# Static analysis
composer analyse

# Code style
composer lint

# Full CI pipeline
composer ci
```

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## License

MIT License. See [LICENSE](LICENSE) for details.
