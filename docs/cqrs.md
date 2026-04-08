# CQRS — Commands & Queries

VibeFW supports Command Query Responsibility Segregation: commands change state, queries read state.

## Commands

Commands represent write operations. They are `final readonly` data transfer objects.

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
use App\Commands\CreatePost;
use App\Requests\StorePostRequest;
use Fw\Validation\ValidationException;

public function store(Request $request): Response
{
    try {
        $data = StorePostRequest::fromRequest($request);
    } catch (ValidationException $e) {
        return $this->view('posts.create', ['errors' => $e->errors]);
    }

    $user = $this->user()->unwrapOr(null);

    $result = $this->dispatch(new CreatePost(
        title: $data->title,
        content: $data->content,
        userId: $user->id,
    ));

    return $result->match(
        ok: fn($post) => $this->redirect('/posts/' . $post->id),
        err: fn($errors) => $this->view('posts.create', ['errors' => $errors]),
    );
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
            none: fn() => Result::err('Post not found'),
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
        ok: fn($post) => $this->view('posts.show', ['post' => $post]),
        err: fn($error) => $this->notFound(),
    );
}
```

## Handler Registration

### Convention-Based (Default)

Handlers are discovered by naming convention:
- `CreatePost` → `CreatePostHandler`
- `GetPostById` → `GetPostByIdHandler`

### Explicit Registration

```php
<?php

namespace App\Providers;

use Fw\Providers\BusServiceProvider as BaseBusServiceProvider;

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

## Bus Middleware

Add cross-cutting concerns:

```php
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

## More Examples

### UpdatePost

```php
final readonly class UpdatePost implements Command
{
    public function __construct(
        public int $id,
        public string $title,
        public string $content,
        public int $userId,
    ) {}
}

final class UpdatePostHandler implements Handler
{
    public function handle(UpdatePost $command): Result
    {
        return Post::find($command->id)->match(
            some: function ($post) use ($command) {
                if ($post->user_id !== $command->userId) {
                    return Result::err('Not authorized');
                }

                $post->fill([
                    'title' => $command->title,
                    'content' => $command->content,
                ])->save();

                return Result::ok($post);
            },
            none: fn() => Result::err('Post not found'),
        );
    }
}
```

### SearchPosts

```php
final readonly class SearchPosts implements Query
{
    public function __construct(
        public string $term,
        public int $limit = 20,
    ) {}
}

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
