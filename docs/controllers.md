# Controllers

Controllers handle HTTP requests and return responses. They live in `app/Controllers/` and extend `Fw\Core\Controller`.

## Creating a Controller

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;

class PostController extends Controller
{
    public function index(Request $request): Response
    {
        $posts = Post::all();
        return $this->view('posts.index', compact('posts'));
    }
}
```

## Request & Response

VibeFW v2.0.0 uses immutable/readonly objects for handling the HTTP lifecycle.

### Request Object

The `Request` object provides access to all incoming data. All its public properties are **readonly**.

```php
$request->method;           // GET, POST, PUT, etc.
$request->uri;              // /posts/1
$request->query;            // Readonly array of GET data
$request->post;             // Readonly array of POST data
$request->server;           // Readonly array of $_SERVER
$request->files;            // Readonly array of $_FILES
$request->headers;          // Readonly array of headers
```

#### Request Methods

```php
$request->get('key');       // Safely get from query string
$request->post('key');      // Safely get from POST data
$request->all();            // Combined input (query + post)
$request->header('Accept'); // Get specific header
$request->isAjax();         // Boolean
$request->ip();             // Client IP address
```

### Response Object

The `Response` object is a **value object**. Most methods return a new instance or the same instance after modification (FW uses internal mutation for performance where safe, but treats it as a value object).

```php
// Basic response
return new Response('Hello World', 200);

// Using fluent interface
return (new Response())
    ->setStatus(201)
    ->setBody('Created')
    ->header('X-Resource-Id', '123')
    ->contentType('application/json');

// Redirects
return (new Response())->redirect('/home');
return (new Response())->redirect('/login', 302);

// Caching
return (new Response())
    ->setBody($content)
    ->cache(3600); // Cache for 1 hour
```

## Controller Methods

### Resource Controller Pattern

```php
class PostController extends Controller
{
    // GET /posts
    public function index(Request $request): Response
    {
        $posts = Post::all();
        return $this->view('posts.index', compact('posts'));
    }

    // GET /posts/create
    public function create(Request $request): Response
    {
        return $this->view('posts.create');
    }

    // POST /posts
    public function store(Request $request): Response
    {
        $validation = $this->validate($request, [
            'title' => 'required|min:3',
            'content' => 'required|min:10',
        ]);

        if ($validation->isErr()) {
            return $this->view('posts.create', [
                'errors' => $validation->getError(),
                'old' => $request->all(),
            ]);
        }

        $post = Post::create($validation->getValue());
        return $this->redirect('/posts/' . $post->getKey());
    }

    // GET /posts/{id}
    public function show(Request $request, string $id): Response
    {
        return Post::find((int) $id)->match(
            some: fn($post) => $this->view('posts.show', compact('post')),
            none: fn() => $this->notFound('Post not found')
        );
    }

    // GET /posts/{id}/edit
    public function edit(Request $request, string $id): Response
    {
        return Post::find((int) $id)->match(
            some: fn($post) => $this->view('posts.edit', compact('post')),
            none: fn() => $this->notFound('Post not found')
        );
    }

    // PUT /posts/{id}
    public function update(Request $request, string $id): Response
    {
        return Post::find((int) $id)->match(
            some: function($post) use ($request) {
                $validation = $this->validate($request, [
                    'title' => 'required|min:3',
                    'content' => 'required|min:10',
                ]);

                if ($validation->isErr()) {
                    return $this->view('posts.edit', [
                        'post' => $post,
                        'errors' => $validation->getError(),
                    ]);
                }

                $post->update($validation->getValue());
                return $this->redirect('/posts/' . $post->getKey());
            },
            none: fn() => $this->notFound('Post not found')
        );
    }

    // DELETE /posts/{id}
    public function destroy(Request $request, string $id): Response
    {
        return Post::find((int) $id)->match(
            some: function($post) {
                $post->delete();
                return $this->redirect('/posts');
            },
            none: fn() => $this->notFound('Post not found')
        );
    }
}
```

## Response Helpers

### Rendering Views

```php
// Render a view with data
return $this->view('posts.index', ['posts' => $posts]);

// View path maps to: app/Views/posts/index.php
```

### JSON Responses

```php
// Return JSON
return $this->json(['success' => true, 'data' => $posts]);

// With status code
return $this->json(['error' => 'Not found'], 404);
```

### Redirects

```php
// Redirect to URL
return $this->redirect('/posts');

// Redirect back to previous page
return $this->back();
```

### Error Responses

```php
return $this->notFound('Resource not found');      // 404
return $this->forbidden('Access denied');          // 403
return $this->badRequest('Invalid input');         // 400
return $this->serverError('Something went wrong'); // 500
return $this->noContent();                         // 204
```

## Request Data

### Request Input

Use these controller helper methods for convenient access:

```php
// Get single value
$title = $this->input($request, 'title');
$title = $this->input($request, 'title', 'Default');

// Get multiple values
$data = $this->only($request, ['title', 'content']);

// Get all except certain fields
$data = $this->except($request, ['_token', '_method']);

// Check if input exists
if ($this->has($request, 'published')) {
    // ...
}
```

See the **Request Object** section above for low-level access via `$request`.

## Validation

```php
$validation = $this->validate($request, [
    'title' => 'required|min:3|max:200',
    'email' => 'required|email',
    'age' => 'numeric|min:0|max:150',
    'website' => 'url',
    'uuid' => 'uuid',
]);

// Check result
if ($validation->isErr()) {
    $errors = $validation->getError();
    // ['title' => 'Title must be at least 3 characters']
}

// Get validated data
$data = $validation->getValue();
```

See [Validation](validation.md) for all available rules.

## Authentication

```php
// Check if user is logged in
if ($this->isAuthenticated()) {
    // ...
}

// Get current user (returns Option)
$this->user()->match(
    some: fn($user) => "Hello, {$user->name}",
    none: fn() => "Hello, Guest"
);
```

## Dispatching Commands & Queries

Using CQRS pattern:

```php
use App\Commands\CreatePost;
use App\Queries\GetPostById;

// Dispatch a command (write operation)
$result = $this->dispatch(new CreatePost(
    title: $data['title'],
    content: $data['content'],
    userId: $this->user()->unwrap()->id,
));

// Dispatch a query (read operation)
$result = $this->query(new GetPostById($id));
```

## Emitting Events

```php
use App\Events\PostCreated;

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

For simple actions, use `__invoke`:

```php
class ShowDashboard extends Controller
{
    public function __invoke(Request $request): Response
    {
        return $this->view('dashboard');
    }
}

// Route
$router->get('/dashboard', ShowDashboard::class);
```

## Dependency Injection

Controllers receive the `Application` instance. Access services via the container:

```php
class PostController extends Controller
{
    public function index(Request $request): Response
    {
        $cache = $this->app->getContainer()->get(CacheInterface::class);
        // ...
    }
}
```
