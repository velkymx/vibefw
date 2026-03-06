# CQRS - Commands & Queries

VibeFW supports the Command Query Responsibility Segregation (CQRS) pattern, separating write operations (commands) from read operations (queries).

## Why CQRS?

- **Clear intent** - Commands change state, queries read state
- **Testability** - Business logic isolated in handlers
- **Scalability** - Commands and queries can be scaled independently
- **Middleware** - Cross-cutting concerns (logging, transactions) in one place

## Commands

Commands represent write operations that change application state.

### Creating a Command

```php
<?php

declare(strict_types=1);

namespace App\Commands;

use Fw\Bus\Command;

final readonly class CreatePost implements Command
{
    public function __construct(
        public string $title,
        public string $content,
        public int $userId,
    ) {}
}
```

### Creating a Handler

```php
<?php

declare(strict_types=1);

namespace App\Handlers;

use Fw\Bus\Handler;
use Fw\Support\Result;
use App\Commands\CreatePost;
use App\Models\Post;

final class CreatePostHandler implements Handler
{
    public function handle(CreatePost $command): Result
    {
        // Validation
        if (strlen($command->title) < 3) {
            return Result::err(['title' => 'Title must be at least 3 characters']);
        }

        // Create post
        $post = Post::create([
            'title' => $command->title,
            'content' => $command->content,
            'user_id' => $command->userId,
        ]);

        return Result::ok($post);
    }
}
```

### Dispatching Commands

```php
class PostController extends Controller
{
    public function store(Request $request): Response
    {
        $validation = $this->validate($request, [
            'title' => 'required|min:3',
            'content' => 'required',
        ]);

        if ($validation->isErr()) {
            return $this->view('posts.create', ['errors' => $validation->getError()]);
        }

        $data = $validation->getValue();
        $user = $this->user()->unwrap();

        $result = $this->dispatch(new CreatePost(
            title: $data['title'],
            content: $data['content'],
            userId: $user->id,
        ));

        return $result->match(
            ok: fn($post) => $this->redirect('/posts/' . $post->id),
            err: fn($errors) => $this->view('posts.create', compact('errors'))
        );
    }
}
```

## Queries

Queries represent read operations that don't change state.

### Creating a Query

```php
<?php

declare(strict_types=1);

namespace App\Queries;

use Fw\Bus\Query;

final readonly class GetPostById implements Query
{
    public function __construct(
        public int $id,
    ) {}
}
```

### Creating a Query Handler

```php
<?php

declare(strict_types=1);

namespace App\Handlers;

use Fw\Bus\Handler;
use Fw\Support\Result;
use App\Queries\GetPostById;
use App\Models\Post;

final class GetPostByIdHandler implements Handler
{
    public function handle(GetPostById $query): Result
    {
        return Post::find($query->id)->match(
            some: fn($post) => Result::ok($post),
            none: fn() => Result::err('Post not found')
        );
    }
}
```

### Dispatching Queries

```php
public function show(Request $request, string $id): Response
{
    $result = $this->query(new GetPostById((int) $id));

    return $result->match(
        ok: fn($post) => $this->view('posts.show', compact('post')),
        err: fn($error) => $this->notFound($error)
    );
}
```

## Handler Registration

### Convention-Based

By default, handlers are discovered by convention:
- `CreatePost` command → `CreatePostHandler` handler
- `GetPostById` query → `GetPostByIdHandler` handler

### Explicit Registration

Register handlers in a service provider:

```php
<?php

namespace App\Providers;

use Fw\Providers\BusServiceProvider as BaseBusServiceProvider;
use App\Commands\CreatePost;
use App\Handlers\CreatePostHandler;
use App\Queries\GetPostById;
use App\Handlers\GetPostByIdHandler;

class BusServiceProvider extends BaseBusServiceProvider
{
    protected array $commands = [
        CreatePost::class => CreatePostHandler::class,
    ];

    protected array $queries = [
        GetPostById::class => GetPostByIdHandler::class,
    ];
}
```

## Command Examples

### CreateUser

```php
// Command
final readonly class CreateUser implements Command
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
    ) {}
}

// Handler
final class CreateUserHandler implements Handler
{
    public function handle(CreateUser $command): Result
    {
        // Check existing
        if (User::where('email', $command->email)->first()->isSome()) {
            return Result::err(['email' => 'Email already registered']);
        }

        $user = User::create([
            'name' => $command->name,
            'email' => $command->email,
            'password' => password_hash($command->password, PASSWORD_DEFAULT),
        ]);

        return Result::ok($user);
    }
}
```

### UpdatePost

```php
// Command
final readonly class UpdatePost implements Command
{
    public function __construct(
        public int $id,
        public string $title,
        public string $content,
        public int $userId,
    ) {}
}

// Handler
final class UpdatePostHandler implements Handler
{
    public function handle(UpdatePost $command): Result
    {
        return Post::find($command->id)->match(
            some: function ($post) use ($command) {
                // Check ownership
                if ($post->user_id !== $command->userId) {
                    return Result::err('Not authorized');
                }

                $post->update([
                    'title' => $command->title,
                    'content' => $command->content,
                ]);

                return Result::ok($post);
            },
            none: fn() => Result::err('Post not found')
        );
    }
}
```

### DeletePost

```php
// Command
final readonly class DeletePost implements Command
{
    public function __construct(
        public int $id,
        public int $userId,
    ) {}
}

// Handler
final class DeletePostHandler implements Handler
{
    public function handle(DeletePost $command): Result
    {
        return Post::find($command->id)->match(
            some: function ($post) use ($command) {
                if ($post->user_id !== $command->userId) {
                    return Result::err('Not authorized');
                }

                $post->delete();
                return Result::ok(true);
            },
            none: fn() => Result::err('Post not found')
        );
    }
}
```

## Query Examples

### GetUserPosts

```php
// Query
final readonly class GetUserPosts implements Query
{
    public function __construct(
        public int $userId,
        public int $limit = 10,
        public int $offset = 0,
    ) {}
}

// Handler
final class GetUserPostsHandler implements Handler
{
    public function handle(GetUserPosts $query): Result
    {
        $posts = Post::where('user_id', $query->userId)
            ->orderBy('created_at', 'desc')
            ->limit($query->limit)
            ->offset($query->offset)
            ->get();

        return Result::ok($posts);
    }
}
```

### SearchPosts

```php
// Query
final readonly class SearchPosts implements Query
{
    public function __construct(
        public string $term,
        public int $limit = 20,
    ) {}
}

// Handler
final class SearchPostsHandler implements Handler
{
    public function handle(SearchPosts $query): Result
    {
        $posts = Post::where('title', 'LIKE', "%{$query->term}%")
            ->orWhere('content', 'LIKE', "%{$query->term}%")
            ->whereNotNull('published_at')
            ->limit($query->limit)
            ->get();

        return Result::ok($posts);
    }
}
```

## Bus Middleware

Add cross-cutting concerns with middleware:

```php
// In BusServiceProvider
public function boot(): void
{
    $bus = $this->container->get(CommandBus::class);

    // Log all commands
    $bus->middleware(function ($command, $next) {
        Log::info('Dispatching: ' . get_class($command));
        $result = $next($command);
        Log::info('Result: ' . ($result->isOk() ? 'ok' : 'err'));
        return $result;
    });

    // Wrap in transaction
    $bus->middleware(function ($command, $next) {
        return DB::transaction(fn() => $next($command));
    });
}
```

## Best Practices

1. **Commands are immutable** - Use `readonly` classes
2. **One handler per command/query** - Single responsibility
3. **Handlers return Result** - Never throw exceptions
4. **Keep commands simple** - Data transfer objects only
5. **Business logic in handlers** - Not in commands or controllers
6. **Use queries for complex reads** - Don't query in controllers
