# Routing

Routes map URLs to controller actions. They're defined in `config/routes.php`.

## Basic Routing

```php
// config/routes.php
return function (Router $router): void {
    // GET request
    $router->get('/', [HomeController::class, 'index']);

    // POST request
    $router->post('/posts', [PostController::class, 'store']);

    // PUT request
    $router->put('/posts/{id}', [PostController::class, 'update']);

    // PATCH request
    $router->patch('/posts/{id}', [PostController::class, 'update']);

    // DELETE request
    $router->delete('/posts/{id}', [PostController::class, 'destroy']);

    // OPTIONS request
    $router->options('/api/posts', [ApiController::class, 'options']);
};
```

## Route Parameters

### Required Parameters

```php
$router->get('/posts/{id}', [PostController::class, 'show']);
$router->get('/users/{userId}/posts/{postId}', [PostController::class, 'show']);
```

Parameters are passed to the controller method:

```php
public function show(Request $request, string $id): Response
{
    // $id contains the value from the URL
}

public function show(Request $request, string $userId, string $postId): Response
{
    // Multiple parameters in order
}
```

### Parameter Constraints

```php
// Only match numeric IDs
$router->get('/posts/{id:\d+}', [PostController::class, 'show']);

// Only match slugs (alphanumeric with dashes)
$router->get('/posts/{slug:[a-z0-9-]+}', [PostController::class, 'show']);

// Match UUIDs
$router->get('/posts/{uuid:[a-f0-9-]{36}}', [PostController::class, 'show']);
```

## Named Routes

```php
$router->get('/posts', [PostController::class, 'index'], 'posts.index');
$router->get('/posts/{id}', [PostController::class, 'show'], 'posts.show');
```

Generate URLs from names:

```php
// In controllers
$url = $this->app->router->url('posts.show', ['id' => 1]);
// Returns: /posts/1

// In views
<a href="<?= $url('posts.show', ['id' => $post->id]) ?>">View Post</a>
```

## Route Groups

### Prefix Groups

```php
$router->group('/admin', function (Router $router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
    $router->get('/users', [AdminController::class, 'users']);
    $router->get('/settings', [AdminController::class, 'settings']);
});

// Creates routes:
// /admin/dashboard
// /admin/users
// /admin/settings
```

### Groups with Middleware

```php
$router->group('/admin', function (Router $router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
    $router->get('/users', [AdminController::class, 'users']);
}, ['auth', 'can:admin']);
```

### Nested Groups

```php
$router->group('/api', function (Router $router) {
    $router->group('/v1', function (Router $router) {
        $router->get('/posts', [Api\V1\PostController::class, 'index']);
    });

    $router->group('/v2', function (Router $router) {
        $router->get('/posts', [Api\V2\PostController::class, 'index']);
    });
}, ['throttle']);

// Creates routes:
// /api/v1/posts
// /api/v2/posts
```

## Middleware

### Single Middleware

```php
$router->get('/dashboard', [DashboardController::class, 'index'])
    ->middleware('auth');
```

### Multiple Middleware

```php
$router->post('/posts', [PostController::class, 'store'])
    ->middleware(['auth', 'csrf', 'can:create,post']);
```

### Middleware with Parameters

```php
// Rate limiting
$router->post('/api/posts', [ApiController::class, 'store'])
    ->middleware('throttle:60,1');  // 60 requests per minute

// Authorization
$router->put('/posts/{id}', [PostController::class, 'update'])
    ->middleware('can:edit,post');
```

See [Middleware](middleware.md) for available middleware.

## HTTP Method Spoofing

HTML forms only support GET and POST. For PUT, PATCH, DELETE, use method spoofing:

```html
<form method="POST" action="/posts/1">
    <?= $csrf() ?>
    <input type="hidden" name="_method" value="PUT">
    <!-- form fields -->
</form>
```

The framework automatically detects `_method` and routes accordingly.

## Resource Routes

For CRUD operations, define resource routes:

```php
// Manual resource routes
$router->get('/posts', [PostController::class, 'index'], 'posts.index');
$router->get('/posts/create', [PostController::class, 'create'], 'posts.create');
$router->post('/posts', [PostController::class, 'store'], 'posts.store');
$router->get('/posts/{id}', [PostController::class, 'show'], 'posts.show');
$router->get('/posts/{id}/edit', [PostController::class, 'edit'], 'posts.edit');
$router->put('/posts/{id}', [PostController::class, 'update'], 'posts.update');
$router->delete('/posts/{id}', [PostController::class, 'destroy'], 'posts.destroy');
```

## API Routes

```php
$router->group('/api', function (Router $router) {
    // Public endpoints
    $router->get('/posts', [Api\PostController::class, 'index']);
    $router->get('/posts/{id}', [Api\PostController::class, 'show']);

    // Protected endpoints
    $router->group('', function (Router $router) {
        $router->post('/posts', [Api\PostController::class, 'store']);
        $router->put('/posts/{id}', [Api\PostController::class, 'update']);
        $router->delete('/posts/{id}', [Api\PostController::class, 'destroy']);
    }, ['api.auth']);
}, ['throttle']);
```

## Route Lifecycle

In VibeFW v2.0.0, the route handling process is side-effect free. The `Router` receives a `Request` object and dispatches it through any middleware to the controller, which must return a `Response` object.

```php
// In VibeFW v2.0.0, this core process always returns a Response
$response = $router->dispatch($request);
```

> **Note:** The `Request` object properties are `readonly` and the `Response` object is a value object. Avoid using global state or superglobals like `$_GET` or `$_POST` directly in your controllers.

## Fallback Routes

Handle 404s with a catch-all:

```php
$router->get('/{path:.*}', [ErrorController::class, 'notFound']);
```

**Note:** Place catch-all routes last.

## Route Caching

For production, cache routes for better performance:

```php
// In a deployment script
$router->saveCache();

// Load cached routes
$router->loadCache();
```

## Complete Example

```php
<?php
// config/routes.php

use App\Controllers\HomeController;
use App\Controllers\PostController;
use App\Controllers\UserController;
use App\Controllers\Auth\LoginController;
use App\Controllers\Auth\RegisterController;
use App\Controllers\Api;
use Fw\Core\Router;

return function (Router $router): void {
    // Public routes
    $router->get('/', [HomeController::class, 'index'], 'home');

    // Authentication
    $router->get('/login', [LoginController::class, 'show'], 'login');
    $router->post('/login', [LoginController::class, 'login']);
    $router->post('/logout', [LoginController::class, 'logout'], 'logout')
        ->middleware('auth');

    $router->get('/register', [RegisterController::class, 'show'], 'register');
    $router->post('/register', [RegisterController::class, 'register']);

    // Posts (public read, auth write)
    $router->get('/posts', [PostController::class, 'index'], 'posts.index');
    $router->get('/posts/{id}', [PostController::class, 'show'], 'posts.show');

    $router->group('/posts', function (Router $router) {
        $router->get('/create', [PostController::class, 'create'], 'posts.create');
        $router->post('', [PostController::class, 'store'], 'posts.store');
        $router->get('/{id}/edit', [PostController::class, 'edit'], 'posts.edit');
        $router->put('/{id}', [PostController::class, 'update'], 'posts.update');
        $router->delete('/{id}', [PostController::class, 'destroy'], 'posts.destroy');
    }, ['auth', 'csrf']);

    // Admin area
    $router->group('/admin', function (Router $router) {
        $router->get('/dashboard', [AdminController::class, 'dashboard']);
        $router->get('/users', [UserController::class, 'index']);
    }, ['auth', 'can:admin']);

    // API
    $router->group('/api', function (Router $router) {
        $router->get('/posts', [Api\PostController::class, 'index']);
        $router->get('/posts/{id}', [Api\PostController::class, 'show']);

        $router->group('', function (Router $router) {
            $router->post('/posts', [Api\PostController::class, 'store']);
            $router->put('/posts/{id}', [Api\PostController::class, 'update']);
            $router->delete('/posts/{id}', [Api\PostController::class, 'destroy']);
        }, ['api.auth']);
    }, ['throttle:100,1']);
};
```
