# Middleware

Middleware filters HTTP requests before they reach your controller.

```
Request → Middleware 1 → Middleware 2 → Controller → Response
              ↓              ↓              ↓
         (can abort)   (can modify)   (generates)
```

## Using Middleware

Middleware uses class references via `$router->with()`. No string aliases. See [routing.md](routing.md) for how `with()` and `group()` combine.

```php
use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;

// Wrap routes in middleware
$router->with(AuthMiddleware::class, function (Router $r) {
    $r->get('/dashboard', [DashboardController::class, 'index']);
    $r->get('/posts/create', [PostController::class, 'create']);

    // Nested middleware
    $r->with(AdminMiddleware::class, function (Router $r) {
        $r->get('/admin', [AdminController::class, 'index']);
    });
});
```

## Built-in Middleware

| Class | Description |
|-------|-------------|
| `AuthMiddleware` | Requires authenticated user |
| `GuestMiddleware` | Requires unauthenticated user |
| `CsrfMiddleware` | Validates CSRF token |
| `CorsMiddleware` | Adds CORS headers |
| `RateLimitMiddleware` | Rate limiting |
| `SecurityHeadersMiddleware` | Security headers |
| `ApiAuthMiddleware` | API token authentication |
| `SpaAuthMiddleware` | SPA cookie authentication |
| `TokenAbilityMiddleware` | Token ability check |

## Global Middleware

Global middleware runs on every request. Configure in `config/middleware.php`. To register middleware as a service, see [providers.md](providers.md).

```php
return [
    'global' => [
        SecurityHeadersMiddleware::class,
        GuestPageCacheMiddleware::class,
    ],
];
```

## Creating Middleware

```bash
php fw make:middleware RateLimitMiddleware
```

### Basic Middleware

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Middleware\MiddlewareInterface;

class LogRequestMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Application $app,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        // Before request
        $start = microtime(true);

        // Pass to next middleware
        $response = $next($request);

        // After response
        $duration = microtime(true) - $start;
        $this->app->log->info('Request completed', [
            'uri' => $request->uri,
            'method' => $request->method,
            'duration' => $duration,
        ]);

        return $response;
    }
}
```

### Early Return (Abort)

Return a Response directly without calling `$next`. The remaining middleware and the controller are skipped entirely:

```php
public function handle(Request $request, callable $next): Response
{
    if (!isset($_SESSION['user'])) {
        $_SESSION['intended_url'] = $request->uri;
        return (new Response())->redirect('/login');  // skips controller
    }

    return $next($request);
}
```

### Modifying Response

```php
public function handle(Request $request, callable $next): Response
{
    $response = $next($request);                      // controller runs
    $response->header('X-Custom-Header', 'value');    // modify after
    return $response;
}
```

### Exception Handling

If the controller or a downstream middleware throws, the exception propagates up through the middleware stack. Catch it if you need to handle or log it:

```php
public function handle(Request $request, callable $next): Response
{
    try {
        return $next($request);
    } catch (\Throwable $e) {
        $this->app->log->error($e->getMessage());
        return (new Response('Internal Server Error', 500));
    }
}
```

Middleware itself must not throw — any uncaught exception becomes a 500 response.

## Middleware Order

Middleware executes in nesting order. Place authentication before authorization:

```php
$router->with(AuthMiddleware::class, function (Router $r) {
    // User is authenticated here
    $r->with(AdminMiddleware::class, function (Router $r) {
        // User is authenticated AND admin here
        $r->get('/admin', [AdminController::class, 'index']);
    });
});
```

## Examples

### Authentication

For the full authentication flow — login, session, and token auth — see [authentication.md](authentication.md).

```php
class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private Application $app) {}

    public function handle(Request $request, callable $next): Response
    {
        if (!isset($_SESSION['user'])) {
            $_SESSION['intended_url'] = $request->uri;
            return (new Response())->redirect('/login');
        }

        return $next($request);
    }
}
```

### Rate Limiting

```php
class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Application $app,
        private int $maxRequests = 60,
        private int $decayMinutes = 1,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $key = 'rate_limit:' . ($request->ip() ?? 'unknown');
        $attempts = $this->getAttempts($key);

        if ($attempts >= $this->maxRequests) {
            return (new Response('Too Many Requests', 429))
                ->header('Retry-After', (string) ($this->decayMinutes * 60));
        }

        $this->incrementAttempts($key);
        return $next($request);
    }
}
```
