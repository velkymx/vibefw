# Result & Option Types

VibeFW uses `Result` and `Option` types instead of exceptions and null values. Both require exhaustive handling via `match()`.

## Option — Values That Might Be Missing

`Option<T>` replaces nullable returns. Model `find()` returns `Option`, not `null`.

### Exhaustive Handling

```php
User::find($id)->match(
    some: fn($user) => $this->view('users.show', ['user' => $user]),
    none: fn() => $this->notFound(),
);
```

### Creating Options

```php
use Fw\Support\Option;

$some = Option::some($value);
$none = Option::none();
$option = Option::fromNullable($maybeNull);
```

### Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `match(some:, none:)` | `mixed` | Exhaustive — handle both cases |
| `map(callable)` | `Option` | Transform value if Some |
| `filter(callable)` | `Option` | Conditionally become None |
| `unwrapOr(mixed)` | `mixed` | Get value or default (escape hatch) |
| `isSome()` | `bool` | Check if Some |
| `isNone()` | `bool` | Check if None |

### Transforming

```php
$name = User::find($id)
    ->map(fn($user) => $user->name)
    ->unwrapOr('Guest');

$activeUser = User::find($id)
    ->filter(fn($user) => $user->isActive())
    ->unwrapOr(null);
```

### Chaining

```php
$email = User::find($id)
    ->flatMap(fn($user) => $user->profile())
    ->map(fn($profile) => $profile->email)
    ->unwrapOr('no-email@example.com');
```

## Result — Operations That Can Fail

`Result<T, E>` replaces exceptions for expected failures. Database saves, API calls, and validation return `Result`.

### Exhaustive Handling

```php
$post->save()->match(
    ok:  fn($p) => $this->redirect('/posts/' . $p->id),
    err: fn($e) => $this->view('posts.create', ['error' => $e->message]),
);
```

### Creating Results

```php
use Fw\Support\Result;

$ok = Result::ok($value);
$err = Result::err($error);
```

### Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `match(ok:, err:)` | `mixed` | Exhaustive — handle both cases |
| `map(callable)` | `Result` | Transform ok value |
| `mapErr(callable)` | `Result` | Transform error value |
| `andThen(callable)` | `Result` | Chain Result-returning operations |
| `isOk()` | `bool` | Check if Ok |
| `isErr()` | `bool` | Check if Err |

### Chaining

```php
$user->save()
    ->map(fn($u) => $this->sendEmail($u))
    ->mapErr(fn($e) => $this->logError($e));

$this->fetchUser($id)
    ->andThen(fn($user) => $this->fetchPosts($user->id));
```

## `#[\NoDiscard]`

All methods returning `Result` or `Option` are annotated with `#[\NoDiscard]`. PHP warns if you ignore the return value:

```php
// Warning: Return value of Post::save() is not used
$post->save();

// Correct — handle the result
$post->save()->match(
    ok: fn($p) => $this->redirect('/posts/' . $p->id),
    err: fn($e) => $this->view('posts.create', ['error' => $e]),
);
```

## Common Patterns

### Controller with Option

```php
public function show(Request $request, string $id): Response
{
    return Post::find((int) $id)->match(
        some: fn($post) => $this->view('posts.show', ['post' => $post]),
        none: fn() => $this->notFound(),
    );
}
```

### Controller with Result

```php
public function store(Request $request): Response
{
    try {
        $data = StorePostRequest::fromRequest($request);
    } catch (ValidationException $e) {
        return $this->view('posts.create', ['errors' => $e->errors]);
    }

    return Post::create($data->toArray())->save()->match(
        ok: fn($post) => $this->redirect('/posts/' . $post->id),
        err: fn($error) => $this->view('posts.create', ['error' => $error]),
    );
}
```

### Service Methods

```php
class UserService
{
    public function createUser(array $data): Result
    {
        if (User::where('email', '=', $data['email'])->first()->isSome()) {
            return Result::err(['email' => 'Email already exists']);
        }

        $user = User::create($data);
        return Result::ok($user);
    }

    public function findByEmail(string $email): Option
    {
        return User::where('email', '=', $email)->first();
    }
}
```

## Rules

1. **Never return null** — use `Option::none()`
2. **Never throw for expected failures** — use `Result::err()`
3. **Always handle both cases** — use `match()` for exhaustive handling
4. **Chain with map/flatMap** — avoid nested conditionals
