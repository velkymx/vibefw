# Result & Option Types

VibeFW uses `Result` and `Option` types instead of exceptions and null values. This makes error handling explicit and prevents null pointer errors.

## Why?

Traditional PHP:
```php
// Problems:
// - What if find() returns null?
// - What if createUser() throws an exception?
// - Null checks scattered everywhere

$user = User::find($id);  // Could be null
if ($user === null) {
    return $this->notFound();
}

try {
    $post = $this->createPost($data);
} catch (Exception $e) {
    return $this->error($e->getMessage());
}
```

With Result/Option:
```php
// Clear, explicit error handling
// Compiler-enforced null safety
// No try/catch for control flow

User::find($id)->match(
    some: fn($user) => $this->view('users.show', compact('user')),
    none: fn() => $this->notFound()
);

$this->createPost($data)->match(
    ok: fn($post) => $this->redirect('/posts/' . $post->id),
    err: fn($error) => $this->view('posts.create', ['errors' => $error])
);
```

## Option Type

`Option` represents a value that may or may not exist. Use instead of `null`.

### Creating Options

```php
use Fw\Support\Option;

// Value exists
$some = Option::some($value);

// Value doesn't exist
$none = Option::none();

// From nullable value
$option = Option::fromNullable($maybeNull);
```

### Using Options

#### Pattern Matching

```php
$result = User::find($id)->match(
    some: fn($user) => "Hello, {$user->name}",
    none: fn() => "User not found"
);
```

#### Checking State

```php
$option = User::find($id);

if ($option->isSome()) {
    $user = $option->unwrap();
}

if ($option->isNone()) {
    // Handle missing value
}
```

#### Getting Values

```php
// Unwrap (throws if None)
$user = $option->unwrap();

// Unwrap with default
$user = $option->unwrapOr(new GuestUser());

// Unwrap with callback
$user = $option->unwrapOrElse(fn() => User::createGuest());
```

#### Transforming

```php
// Map: transform the value if Some
$name = User::find($id)
    ->map(fn($user) => $user->name)
    ->unwrapOr('Guest');

// FlatMap: chain Option-returning operations
$email = User::find($id)
    ->flatMap(fn($user) => $user->profile())
    ->map(fn($profile) => $profile->email)
    ->unwrapOr('no-email@example.com');
```

#### Filtering

```php
$activeUser = User::find($id)
    ->filter(fn($user) => $user->isActive())
    ->unwrapOr(null);
```

### Option in Models

Model `find()` returns `Option`, not nullable:

```php
// WRONG - find() doesn't return nullable
$user = User::find($id);
if ($user === null) { }  // This won't work!

// CORRECT - use Option methods
User::find($id)->match(
    some: fn($user) => $this->view('users.show', compact('user')),
    none: fn() => $this->notFound()
);

// Or with map
$name = User::find($id)
    ->map(fn($u) => $u->name)
    ->unwrapOr('Unknown');
```

## Result Type

`Result` represents an operation that can succeed or fail. Use instead of exceptions.

### Creating Results

```php
use Fw\Support\Result;

// Success
$ok = Result::ok($value);

// Failure
$err = Result::err($error);
```

### Using Results

#### Pattern Matching

```php
$result = $this->createUser($data);

return $result->match(
    ok: fn($user) => $this->redirect('/users/' . $user->id),
    err: fn($errors) => $this->view('users.create', compact('errors'))
);
```

#### Checking State

```php
if ($result->isOk()) {
    $value = $result->getValue();
}

if ($result->isErr()) {
    $error = $result->getError();
}
```

#### Getting Values

```php
// Get value (throws if Err)
$value = $result->getValue();

// Get error (throws if Ok)
$error = $result->getError();

// With defaults
$value = $result->getValueOr($default);
$error = $result->getErrorOr($defaultError);
```

#### Transforming

```php
// Map: transform success value
$result = $this->fetchUser($id)
    ->map(fn($user) => $user->toArray());

// MapErr: transform error
$result = $this->fetchUser($id)
    ->mapErr(fn($e) => "Failed to fetch user: {$e}");

// FlatMap: chain Result-returning operations
$result = $this->fetchUser($id)
    ->flatMap(fn($user) => $this->fetchPosts($user->id));
```

### Result in Validation

```php
$validation = $this->validate($request, [
    'email' => 'required|email',
    'password' => 'required|min:8',
]);

// Pattern matching
return $validation->match(
    ok: fn($data) => $this->login($data),
    err: fn($errors) => $this->view('login', compact('errors'))
);

// Or explicit checks
if ($validation->isErr()) {
    return $this->view('login', ['errors' => $validation->getError()]);
}

$data = $validation->getValue();
```

### Result in CQRS

Commands and queries return `Result`:

```php
$result = $this->dispatch(new CreateUser($data));

return $result->match(
    ok: fn($user) => $this->json(['id' => $user->id], 201),
    err: fn($error) => $this->json(['error' => $error->getMessage()], 400)
);
```

## Common Patterns

### Controller with Option

```php
public function show(Request $request, string $id): Response
{
    return Post::find((int) $id)->match(
        some: fn($post) => $this->view('posts.show', compact('post')),
        none: fn() => $this->notFound('Post not found')
    );
}
```

### Controller with Result

```php
public function store(Request $request): Response
{
    $validation = $this->validate($request, [
        'title' => 'required|min:3',
        'content' => 'required',
    ]);

    if ($validation->isErr()) {
        return $this->view('posts.create', [
            'errors' => $validation->getError(),
        ]);
    }

    $post = Post::create($validation->getValue());
    return $this->redirect('/posts/' . $post->id);
}
```

### Chaining Operations

```php
// Find user → get their posts → get first post → get title
$title = User::find($userId)
    ->map(fn($user) => $user->posts()->get())
    ->map(fn($posts) => $posts->first())
    ->flatMap(fn($post) => $post)  // Option<Option<Post>> → Option<Post>
    ->map(fn($post) => $post->title)
    ->unwrapOr('No posts');
```

### Service Methods

```php
class UserService
{
    public function createUser(array $data): Result
    {
        if (User::where('email', $data['email'])->first()->isSome()) {
            return Result::err(['email' => 'Email already exists']);
        }

        $user = User::create($data);
        return Result::ok($user);
    }

    public function findByEmail(string $email): Option
    {
        return User::where('email', $email)->first();
    }
}
```

## Rules

1. **Never return null** - Use `Option::none()` instead
2. **Never throw for expected failures** - Use `Result::err()` instead
3. **Always handle both cases** - Use `match()` for exhaustive handling
4. **Chain with map/flatMap** - Avoid nested conditionals

## Quick Reference

### Option Methods

| Method | Description |
|--------|-------------|
| `some($value)` | Create Some with value |
| `none()` | Create None |
| `isSome()` | Check if Some |
| `isNone()` | Check if None |
| `unwrap()` | Get value (throws if None) |
| `unwrapOr($default)` | Get value or default |
| `map($fn)` | Transform value if Some |
| `flatMap($fn)` | Chain Option-returning function |
| `filter($predicate)` | Keep value if predicate passes |
| `match(some:, none:)` | Pattern match |

### Result Methods

| Method | Description |
|--------|-------------|
| `ok($value)` | Create Ok with value |
| `err($error)` | Create Err with error |
| `isOk()` | Check if Ok |
| `isErr()` | Check if Err |
| `getValue()` | Get value (throws if Err) |
| `getError()` | Get error (throws if Ok) |
| `map($fn)` | Transform value if Ok |
| `mapErr($fn)` | Transform error if Err |
| `flatMap($fn)` | Chain Result-returning function |
| `match(ok:, err:)` | Pattern match |
