# Error Handling & Response Formats

This doc covers how errors are expressed across web and API responses, and how to handle them in controllers and middleware.

## Web Response Errors

Controller helper methods return standard HTTP error responses. See [controllers.md](controllers.md) for the full controller pattern.

```php
return $this->notFound();       // 404
return $this->forbidden();      // 403
return $this->badRequest();     // 400
return $this->serverError();    // 500
return $this->noContent();      // 204 (no body)
```

These render a plain response with the appropriate status code. If `app/Views/errors/{code}.php` exists, that view is rendered instead:

```
app/Views/errors/
├── 404.php    # Rendered by $this->notFound()
├── 403.php    # Rendered by $this->forbidden()
└── 500.php    # Rendered by $this->serverError()
```

Example `app/Views/errors/404.php`:

```php
<?php $this->layout('main'); ?>
<?php $title = 'Page Not Found'; ?>

<?php $section('content'); ?>
    <h1>404 — Not Found</h1>
    <p>The page you're looking for doesn't exist.</p>
    <a href="<?= $url('home') ?>">Go home</a>
<?php $endSection(); ?>
```

## API Error Responses

For API controllers (`$this->json()`), use consistent JSON error shapes:

### Not Found — 404

```php
return $this->json(['error' => 'Post not found'], 404);
```

```json
{ "error": "Post not found" }
```

### Validation Failure — 422

```php
return $this->json(['errors' => $validationErrors], 422);
```

```json
{
    "errors": {
        "title": "The title field is required.",
        "email": "The email must be a valid email address."
    }
}
```

`errors` is `object<string, string>` — **one string per field, never an array per field**. Rules evaluate in order; the first failure stops the chain for that field. Do not expect `errors.title` to be an array.

### Forbidden — 403

```php
return $this->json(['error' => 'Forbidden'], 403);
```

### Unauthenticated — 401

```php
return $this->json(['error' => 'Unauthenticated'], 401);
```

### Server Error — 500

```php
return $this->json(['error' => 'Internal server error'], 500);
```

## API Validation Pattern

The schema-generated API controller uses this pattern for validation:

```php
public function store(Request $request): Response
{
    $validated = StorePostRequest::fromArray($request->all());

    if ($validated->isErr()) {
        return $this->json(['errors' => $validated->unwrapErr()], 422);
    }

    $post = Post::create($validated->unwrapOr([]));
    return $this->json($post, 201);
}
```

`fromArray()` returns `Result<array, array<string, string>>` — the err value is the same `errors` shape as `ValidationException->errors`.

## Validation Errors in Web Controllers

Web controllers use `ValidationException` and pass errors to the view:

```php
public function store(Request $request): Response
{
    try {
        $data = StorePostRequest::fromRequest($request);
    } catch (ValidationException $e) {
        // $e->errors is array<string, string> — one message per field
        return $this->view('posts.create', [
            'errors' => $e->errors,
            'old'    => $request->all(),
        ]);
    }

    Post::create($data->toArray());
    return $this->redirect('/posts');
}
```

## Exception Hierarchy

| Exception | When thrown | HTTP status |
|-----------|------------|------------|
| `ValidationException` | `FormRequest::fromRequest()` fails | Pass to view (web) or 422 (API) |
| `HandlerNotFoundException` | CQRS bus can't find a handler | 500 |
| `\InvalidArgumentException` | Route registered with closure | Fatal at boot |
| `\RuntimeException` | General framework errors | 500 |

Exceptions that escape the controller are caught by the kernel and rendered as 500 responses (or the `errors/500.php` view in production).

## Result-Based Error Handling

For operations that can fail without exceptional conditions (database writes, external calls), use `Result`. See [result-option.md](result-option.md) for the full Result/Option API.

```php
// Don't throw — return Result
$post->save()->match(
    ok:  fn($p) => $this->redirect('/posts/' . $p->id),
    err: fn($e) => $this->view('posts.create', ['error' => $e]),
);
```

The `err` value is whatever was passed to `Result::err(...)` — a string message, an array, or an object depending on convention. Within a feature, be consistent.

## Model Not Found Pattern

`Model::find()` returns `Option`, not null. Always use `.match()` or `.unwrapOr()`:

```php
// Preferred for web
Post::find($id)->match(
    some: fn($post) => $this->view('posts.show', ['post' => $post]),
    none: fn()      => $this->notFound(),
);

// Preferred for API
$post = Post::find($id)->unwrapOr(null);
if ($post === null) {
    return $this->json(['error' => 'Post not found'], 404);
}
```

Never call `->unwrap()` without checking — it throws `UnwrapException` if the Option is None.
