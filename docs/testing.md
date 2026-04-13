# Testing

## Running Tests

```bash
php fw test                           # All tests
php fw test --testsuite=unit          # Unit tests only
php fw test --testsuite=architecture  # Architecture tests only
php fw test --filter=PostTest         # Filter by name
php fw test --coverage                # With coverage report
composer test                         # Same as php fw test
composer test:unit                    # Unit only
composer test:mutation                # Mutation testing (checks test quality)
```

## Writing Tests

Tests live in `tests/` and use PHPUnit with `#[Test]` attributes.

```php
<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PostTest extends TestCase
{
    #[Test]
    public function itCreatesAPost(): void
    {
        $post = Post::create([
            'title' => 'Test Post',
            'content' => 'Test content',
            'user_id' => 1,
        ]);

        $this->assertSame('Test Post', $post->title);
    }
}
```

## Test Structure

```
tests/
├── Unit/                    # Unit tests
│   ├── PostTest.php
│   ├── UserTest.php
│   └── ...
├── Feature/                 # Feature/integration tests
│   ├── PostControllerTest.php
│   └── ...
└── Architecture/            # Architecture enforcement tests
    ├── ConventionTest.php
    ├── LayerTest.php
    ├── SecurityPatternTest.php
    └── StubPatternsTest.php
```

## Generating Tests

```bash
php fw make:test Post
```

Generates feature and unit tests from the existing model's `$fillable`, relations, and casts — see [models.md](models.md) for model structure. Tests are runnable immediately.

## Finding Tests for a Feature

```bash
php fw test:for post
# Lists all test files related to "post"
```

## Factories

Factories are generated automatically as part of the schema workflow — see [schema.md](schema.md). You can also create them individually:

```bash
php fw make:factory PostFactory    # database/factories/PostFactory.php
```

Factories use Faker to generate test data:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Post;
use Fw\Testing\Factory;

class PostFactory extends Factory
{
    protected string $model = Post::class;

    public function definition(): array
    {
        return [
            'title'      => fake()->sentence(),
            'content'    => fake()->paragraphs(3, true),
            'user_id'    => 1,
            'published_at' => fake()->dateTime(),
        ];
    }
}
```

Using factories in tests:

```php
// Create one model (persisted to database)
$post = PostFactory::new()->create();

// Create with overrides
$post = PostFactory::new()->create(['title' => 'My Title', 'user_id' => $user->id]);

// Create without persisting
$post = PostFactory::new()->make();

// Create many
$posts = PostFactory::new()->count(5)->create();
```

## Test Database

Feature tests that hit the database should use the `RefreshDatabase` trait to roll back after each test:

```php
use Fw\Testing\RefreshDatabase;

final class PostControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function itListsPosts(): void
    {
        PostFactory::new()->count(3)->create();

        // ... assert
    }
}
```

Each test runs in a transaction that is rolled back on teardown. Unit tests that don't touch the database do not need this trait.

## Architecture Tests

Architecture tests enforce framework conventions automatically:

| Test | What It Checks |
|------|---------------|
| `ConventionTest` | Controllers return Response, models declare $fillable, FormRequests implement rules(), no string pipe rules |
| `LayerTest` | Controllers don't access PDO, models don't import controllers, no circular deps |
| `SecurityPatternTest` | No unserialize, no eval, no hardcoded credentials, no debug functions |
| `StubPatternsTest` | All stubs have `// CUSTOMIZE:` markers |

## Full Validation Pipeline

```bash
php fw check                  # Conventions + architecture + PHPStan
php fw validate:all           # Config + security + style + analysis
composer validate             # Same as validate:all
```

## Pre-commit Hook

```bash
cp .hooks/pre-commit .git/hooks/pre-commit
chmod +x .git/hooks/pre-commit
```

Runs on staged PHP files: syntax check, code style, PHPStan, security scan, merge conflict markers.
