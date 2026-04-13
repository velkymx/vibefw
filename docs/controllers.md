# Controllers

Controllers handle HTTP requests and return responses. They live in `app/Controllers/` and extend `Fw\Core\Controller`.

## Creating a Controller

```bash
php fw make:controller PostController          # Basic controller
php fw make:controller PostController -r       # Resource controller: index/create/store/show/edit/update/destroy (--resource)
php fw make:controller Api/PostController -r   # Namespaced (creates app/Controllers/Api/)
```

To generate a controller alongside its model, migration, views, and form requests in one step, use the schema workflow — see [schema.md](schema.md).

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;
use App\Models\Post;

class PostController extends Controller
{
    public function index(Request $request): Response
    {
        $posts = Post::orderBy('created_at', 'desc')->paginate(15, $request->get('page', 1));
        return $this->view('posts.index', ['posts' => $posts]);
    }
}
```

`paginate(perPage, page)` returns:

```php
[
    'items'        => [...],  // array of Model instances for this page
    'total'        => 100,    // total record count
    'per_page'     => 15,
    'current_page' => 1,
    'last_page'    => 7,
]
```

Access in views: `$posts['items']`, `$posts['total']`, `$posts['last_page']`, `$posts['current_page']`.

Every controller method **must** return `Response`. The framework does not normalize strings or arrays.

## Request Object

The `Request` object provides access to all incoming data. Properties are `readonly`.

```php
$request->method;           // GET, POST, PUT, etc.
$request->uri;              // /posts/1
$request->query;            // Readonly array of GET data
$request->post;             // Readonly array of POST data
$request->server;           // Readonly array of $_SERVER
$request->files;            // Readonly array of $_FILES
$request->headers;          // Readonly array of headers
```

### Request Methods

```php
$request->get('key');           // Get from query string
$request->get('key', 'default'); // With default
$request->post('key');          // Get from POST data
$request->all();                // Combined input (query + post)
$request->input('key');         // Get from combined input
$request->has('key');           // Check if input exists
$request->header('Accept');     // Get specific header
$request->isAjax();            // Boolean
$request->ip();                // Client IP address
```

## Response Helpers

### Rendering Views

```php
return $this->view('posts.index', ['posts' => $posts]);
// Renders app/Views/posts/index.php

return $this->cachedView('pages.about', [], 3600);
// Full-page cache for 1 hour

return $this->streamedView('reports.large', ['data' => $data]);
// Streamed render (lower memory, faster TTFB)
```

### JSON Responses

```php
return $this->json(['success' => true, 'data' => $posts]);
return $this->json(['error' => 'Not found'], 404);
```

### Redirects

```php
return $this->redirect('/posts');
return $this->back();
```

### Flash Messages

Flash data is passed on redirect and available in the next view via `$flash`:

```php
return $this->redirect('/posts')
    ->with('success', 'Post created!');

return $this->redirect('/posts')
    ->with('error', 'Something went wrong.');
```

In views:

```php
<?php if (isset($flash['success'])): ?>
    <div class="alert alert-success"><?= $e($flash['success']) ?></div>
<?php endif; ?>

<?php if (isset($flash['error'])): ?>
    <div class="alert alert-error"><?= $e($flash['error']) ?></div>
<?php endif; ?>
```

Flash data persists for exactly one request.

### Error Responses

```php
return $this->notFound();       // 404 — renders app/Views/errors/404.php if it exists
return $this->forbidden();      // 403
return $this->badRequest();     // 400
return $this->serverError();    // 500
return $this->noContent();      // 204 (no body)
```

For API error shapes, consistent JSON format, and how to create custom error views — see [errors.md](errors.md).

### Response Fluent Methods

```php
return $this->redirect('/dashboard')
    ->with('success', 'Profile updated!');

return $this->view('posts.index', ['posts' => $posts])
    ->header('X-Custom', 'value')
    ->cache(3600);

return $this->view('dashboard')->noCache();
```

## Validation with FormRequest

Use `FormRequest` classes for validation. Do not validate inline.

```php
use App\Requests\StorePostRequest;
use Fw\Validation\ValidationException;

public function store(Request $request): Response
{
    try {
        $data = StorePostRequest::fromRequest($request);
    } catch (ValidationException $e) {
        return $this->view('posts.create', ['errors' => $e->errors]);
    }

    $post = Post::create($data->toArray());
    return $this->redirect('/posts/' . $post->id);
}
```

See [Validation](validation.md) for creating FormRequest classes.

## Resource Controller Pattern

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;
use App\Models\Post;
use App\Requests\StorePostRequest;
use App\Requests\UpdatePostRequest;
use Fw\Validation\ValidationException;

class PostController extends Controller
{
    // GET /posts
    public function index(Request $request): Response
    {
        $posts = Post::orderBy('created_at', 'desc')->paginate(15, $request->get('page', 1));
        return $this->view('posts.index', ['posts' => $posts]);
    }

    // GET /posts/create
    public function create(Request $request): Response
    {
        return $this->view('posts.create');
    }

    // POST /posts
    public function store(Request $request): Response
    {
        try {
            $data = StorePostRequest::fromRequest($request);
        } catch (ValidationException $e) {
            return $this->view('posts.create', ['errors' => $e->errors]);
        }

        $post = Post::create($data->toArray());
        return $this->redirect('/posts/' . $post->id);
    }

    // GET /posts/{id}
    // Post::find() returns Option — see result-option.md for match()/unwrapOr() patterns
    public function show(Request $request, string $id): Response
    {
        return Post::find((int) $id)->match(
            some: fn($post) => $this->view('posts.show', ['post' => $post]),
            none: fn() => $this->notFound(),
        );
    }

    // GET /posts/{id}/edit
    public function edit(Request $request, string $id): Response
    {
        return Post::find((int) $id)->match(
            some: fn($post) => $this->view('posts.edit', ['post' => $post]),
            none: fn() => $this->notFound(),
        );
    }

    // PUT /posts/{id}
    public function update(Request $request, string $id): Response
    {
        return Post::find((int) $id)->match(
            some: function ($post) use ($request) {
                try {
                    $data = UpdatePostRequest::fromRequest($request);
                } catch (ValidationException $e) {
                    return $this->view('posts.edit', ['post' => $post, 'errors' => $e->errors]);
                }

                $post->fill($data->toArray())->save();
                return $this->redirect('/posts/' . $post->id);
            },
            none: fn() => $this->notFound(),
        );
    }

    // DELETE /posts/{id}
    public function destroy(Request $request, string $id): Response
    {
        Post::find((int) $id)->match(
            some: fn($post) => $post->delete(),
            none: fn() => null,
        );

        return $this->redirect('/posts');
    }
}
```

## Authentication

```php
// Check if user is logged in
if ($this->isAuthenticated()) {
    // ...
}

// Get current user (returns Option)
$this->user()->match(
    some: fn($user) => "Hello, {$user->name}",
    none: fn() => "Not logged in",
);
```

## Dispatching Commands & Queries

```php
use App\Commands\CreatePost;
use App\Queries\GetPostById;

// Dispatch a command (write operation)
$result = $this->dispatch(new CreatePost(
    title: $data->title,
    content: $data->content,
    userId: $user->id,
));

// Dispatch a query (read operation)
$result = $this->query(new GetPostById($id));

// Emit events
$this->emit(new PostCreated($post));
```

## Organizing Controllers

### Subdirectories

```php
// app/Controllers/Api/PostController.php
namespace App\Controllers\Api;

class PostController extends Controller
{
    // ...
}

// Route registration
$router->get('/api/posts', [Api\PostController::class, 'index']);
```

### Single Action Controllers

```php
class ShowDashboard extends Controller
{
    public function __invoke(Request $request): Response
    {
        return $this->view('dashboard');
    }
}
```
