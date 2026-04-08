# Routing

Routes map URLs to controller actions. They're defined in `config/routes.php`.

## Basic Routing

```php
// config/routes.php
return function (Router $router): void {
    $router->get('/', [HomeController::class, 'index']);
    $router->post('/posts', [PostController::class, 'store']);
    $router->put('/posts/{id}', [PostController::class, 'update']);
    $router->patch('/posts/{id}', [PostController::class, 'update']);
    $router->delete('/posts/{id}', [PostController::class, 'destroy']);
    $router->options('/api/posts', [ApiController::class, 'options']);
};
```

Route handlers **must** be `[Controller::class, 'method']` arrays. Closures throw `InvalidArgumentException` at registration time.

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
    return Post::find((int) $id)->match(
        some: fn($post) => $this->view('posts.show', ['post' => $post]),
        none: fn() => $this->notFound(),
    );
}
```

### Parameter Constraints

```php
$router->get('/posts/{id:\d+}', [PostController::class, 'show']);
$router->get('/posts/{slug:[a-z0-9-]+}', [PostController::class, 'show']);
$router->get('/posts/{uuid:[a-f0-9-]{36}}', [PostController::class, 'show']);
```

## Named Routes

```php
$router->get('/posts', [PostController::class, 'index'], 'posts.index');
$router->get('/posts/{id}', [PostController::class, 'show'], 'posts.show');
```

Generate URLs from names:

```php
// In views
<a href="<?= $url('posts.show', ['id' => $post->id]) ?>">View Post</a>
```

## Typed Middleware

Middleware uses class references, not strings. Typos are fatal errors.

```php
use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;

$router->with(AuthMiddleware::class, function (Router $r) {
    $r->get('/dashboard', [DashboardController::class, 'index'], 'dashboard');
    $r->get('/posts/create', [PostController::class, 'create'], 'posts.create');
    $r->post('/posts', [PostController::class, 'store'], 'posts.store');

    // Nested middleware
    $r->with(AdminMiddleware::class, function (Router $r) {
        $r->get('/admin', [AdminController::class, 'index'], 'admin');
    });
});
```

## Route Groups

### Prefix Groups

```php
$router->group('/admin', function (Router $router) {
    $router->get('/dashboard', [AdminController::class, 'dashboard']);
    $router->get('/users', [AdminController::class, 'users']);
});
// Creates: /admin/dashboard, /admin/users
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
});
```

## Resource Routes

```php
$router->get('/posts', [PostController::class, 'index'], 'posts.index');
$router->get('/posts/create', [PostController::class, 'create'], 'posts.create');
$router->post('/posts', [PostController::class, 'store'], 'posts.store');
$router->get('/posts/{id}', [PostController::class, 'show'], 'posts.show');
$router->get('/posts/{id}/edit', [PostController::class, 'edit'], 'posts.edit');
$router->put('/posts/{id}', [PostController::class, 'update'], 'posts.update');
$router->delete('/posts/{id}', [PostController::class, 'destroy'], 'posts.destroy');
```

## HTTP Method Spoofing

HTML forms only support GET and POST. For PUT, PATCH, DELETE:

```html
<form method="POST" action="/posts/1">
    <?= $csrf() ?>
    <input type="hidden" name="_method" value="PUT">
    <!-- form fields -->
</form>
```

## Complete Example

```php
<?php

declare(strict_types=1);

use Fw\Core\Router;
use App\Controllers\HomeController;
use App\Controllers\PostController;
use App\Controllers\AuthController;
use App\Controllers\Admin\DashboardController;
use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;

return function (Router $router): void {
    // Public
    $router->get('/', [HomeController::class, 'index'], 'home');
    $router->get('/posts', [PostController::class, 'index'], 'posts.index');
    $router->get('/posts/{id}', [PostController::class, 'show'], 'posts.show');

    // Auth
    $router->get('/login', [AuthController::class, 'showLogin'], 'login');
    $router->post('/login', [AuthController::class, 'login']);
    $router->post('/logout', [AuthController::class, 'logout'], 'logout');

    // Protected — typed middleware
    $router->with(AuthMiddleware::class, function (Router $r) {
        $r->get('/dashboard', [DashboardController::class, 'index'], 'dashboard');
        $r->get('/posts/create', [PostController::class, 'create'], 'posts.create');
        $r->post('/posts', [PostController::class, 'store'], 'posts.store');
        $r->get('/posts/{id}/edit', [PostController::class, 'edit'], 'posts.edit');
        $r->put('/posts/{id}', [PostController::class, 'update'], 'posts.update');
        $r->delete('/posts/{id}', [PostController::class, 'destroy'], 'posts.destroy');

        // Admin — nested
        $r->with(AdminMiddleware::class, function (Router $r) {
            $r->get('/admin', [DashboardController::class, 'admin'], 'admin');
        });
    });

    // API
    $router->group('/api', function (Router $router) {
        $router->get('/posts', [Api\PostController::class, 'index']);
        $router->get('/posts/{id}', [Api\PostController::class, 'show']);
    });
};
```

## Route Caching

```bash
php fw route:cache    # Cache routes for production
php fw route:clear    # Clear route cache
```

## Fallback Routes

```php
$router->get('/{path:.*}', [ErrorController::class, 'notFound']);
```

Place catch-all routes last.
