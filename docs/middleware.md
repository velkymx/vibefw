# Middleware

Middleware filters HTTP requests before they reach your controller. They can authenticate users, validate CSRF tokens, add headers, and more.

## How Middleware Works

```
Request → Middleware 1 → Middleware 2 → Controller → Response
              ↓              ↓              ↓
         (can abort)   (can modify)   (generates)
```

Each middleware can:
- Pass the request to the next middleware
- Return a response early (abort the chain)
- Modify the request or response

## Built-in Middleware

| Alias | Class | Description |
|-------|-------|-------------|
| `auth` | `AuthMiddleware` | Requires authenticated user |
| `guest` | `GuestMiddleware` | Requires unauthenticated user |
| `csrf` | `CsrfMiddleware` | Validates CSRF token |
| `cors` | `CorsMiddleware` | Adds CORS headers |
| `can` | `CanMiddleware` | Authorization check |
| `throttle` | `RateLimitMiddleware` | Rate limiting |
| `secure` | `SecurityHeadersMiddleware` | Security headers |
| `api.auth` | `ApiAuthMiddleware` | API token authentication |
| `spa.auth` | `SpaAuthMiddleware` | SPA cookie authentication |
| `ability` | `TokenAbilityMiddleware` | Token ability check |

## Configuration

Middleware is configured in `config/middleware.php`:

```php
return [
    // Applied to every request
    'global' => [
        SecurityHeadersMiddleware::class,
        GuestPageCacheMiddleware::class,
    ],

    // Short aliases
    'aliases' => [
        'auth' => AuthMiddleware::class,
        'guest' => GuestMiddleware::class,
        'csrf' => CsrfMiddleware::class,
        'can' => CanMiddleware::class,
        'throttle' => RateLimitMiddleware::class,
    ],

    // Named groups
    'groups' => [
        'web' => ['csrf'],
        'api' => ['throttle'],
        'authenticated' => ['auth', 'csrf'],
    ],
];
```

## Using Middleware

### In Routes

```php
// Single middleware
$router->get('/dashboard', [DashboardController::class, 'index'])
    ->middleware('auth');

// Multiple middleware
$router->post('/posts', [PostController::class, 'store'])
    ->middleware(['auth', 'csrf']);

// Middleware group
$router->post('/posts', [PostController::class, 'store'])
    ->middleware('authenticated');

// With parameters
$router->get('/admin', [AdminController::class, 'index'])
    ->middleware('can:admin');
```

### In Route Groups

```php
$router->group('/admin', function (Router $router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
    $router->get('/users', [AdminController::class, 'users']);
}, ['auth', 'can:admin']);
```

## Creating Middleware

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

        // Ensure we have a Response object (VibeFW v2.0.0 controllers return Response)
        if (!$response instanceof Response) {
            $response = new Response((string) $response);
        }

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

### Middleware with Parameters

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Middleware\MiddlewareInterface;

class RoleMiddleware implements MiddlewareInterface
{
    private string $role;

    public function __construct(
        private Application $app,
        string $role = 'user',
    ) {
        $this->role = $role;
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = $_SESSION['user'] ?? null;

        if (!$user || $user['role'] !== $this->role) {
            return new Response('Forbidden', 403);
        }

        return $next($request);
    }
}
```

Usage: `->middleware('role:admin')`

### Early Return (Abort)

```php
public function handle(Request $request, callable $next): Response
{
    if (!$this->isAuthorized($request)) {
        // Return early - don't call $next()
        return (new Response())->redirect('/login');
    }

    return $next($request);
}
```

### Modifying Response

```php
public function handle(Request $request, callable $next): Response
{
    $response = $next($request);

    // Add headers to response (Response is a value object)
    if ($response instanceof Response) {
        $response->header('X-Custom-Header', 'value');
    }

    return $response;
}
```

## Registering Custom Middleware

Add to `config/middleware.php`:

```php
'aliases' => [
    // ... existing aliases
    'log' => App\Middleware\LogRequestMiddleware::class,
    'role' => App\Middleware\RoleMiddleware::class,
],
```

## Middleware Examples

### Authentication Check

```php
class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private Application $app) {}

    public function handle(Request $request, callable $next): Response
    {
        if (!isset($_SESSION['user'])) {
            // Store intended URL for redirect after login
            $_SESSION['intended_url'] = $request->uri;
            return (new Response())->redirect('/login');
        }

        return $next($request);
    }
}
```

### CSRF Protection

```php
class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private Application $app) {}

    public function handle(Request $request, callable $next): Response
    {
        // Skip for safe methods
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'])) {
            return $next($request);
        }

        $token = $request->post('_csrf_token')
            ?? $request->header('X-CSRF-Token');

        if (!$this->app->csrf->validate($token)) {
            return new Response('Invalid CSRF token', 419);
        }

        return $next($request);
    }
}
```

### Rate Limiting

```php
class RateLimitMiddleware implements MiddlewareInterface
{
    private int $maxRequests;
    private int $decayMinutes;

    public function __construct(
        private Application $app,
        int|string $maxRequests = 60,
        int|string $decayMinutes = 1,
    ) {
        $this->maxRequests = (int) $maxRequests;
        $this->decayMinutes = (int) $decayMinutes;
    }

    public function handle(Request $request, callable $next): Response
    {
        $key = $this->resolveKey($request);
        $attempts = $this->getAttempts($key);

        if ($attempts >= $this->maxRequests) {
            return (new Response('Too Many Requests', 429))
                ->header('Retry-After', (string) ($this->decayMinutes * 60));
        }

        $this->incrementAttempts($key);

        return $next($request);
    }

    private function resolveKey(Request $request): string
    {
        return 'rate_limit:' . ($request->ip() ?? 'unknown');
    }
    // ... increment/get methods
}
```

### Authorization

```php
class CanMiddleware implements MiddlewareInterface
{
    private string $ability;
    private ?string $model;

    public function __construct(
        private Application $app,
        string $ability = '',
        ?string $model = null,
    ) {
        $this->ability = $ability;
        $this->model = $model;
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = $_SESSION['user'] ?? null;

        if (!$user) {
            return (new Response())->redirect('/login');
        }

        if (!$this->authorize($user, $this->ability, $this->model)) {
            return new Response('Forbidden', 403);
        }

        return $next($request);
    }
}
```

## Global Middleware

Global middleware runs on every request:

```php
// config/middleware.php
'global' => [
    SecurityHeadersMiddleware::class,  // Security headers
    GuestPageCacheMiddleware::class,   // Page caching for guests
],
```

## Middleware Groups

Combine multiple middleware:

```php
'groups' => [
    'web' => [
        'csrf',
    ],
    'api' => [
        'throttle:60,1',
    ],
    'admin' => [
        'auth',
        'csrf',
        'can:admin',
    ],
],
```

Usage: `->middleware('admin')`

## Middleware Order

Middleware executes in the order specified:

```php
->middleware(['auth', 'csrf', 'can:edit'])
// 1. auth - Check if logged in
// 2. csrf - Validate token
// 3. can:edit - Check permission
```

Place authentication before authorization.
