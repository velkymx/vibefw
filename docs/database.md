# Database & Migrations

VibeFW provides a simple database layer with migrations for schema management.

## Configuration

Configure your database in `config/database.php`:

```php
return [
    'enabled' => true,
    'driver' => Env::get('DB_DRIVER', 'sqlite'),
    'database' => Env::get('DB_DATABASE', BASE_PATH . '/database/database.sqlite'),

    // For MySQL/PostgreSQL:
    // 'host' => Env::get('DB_HOST', 'localhost'),
    // 'port' => Env::get('DB_PORT', '3306'),
    // 'username' => Env::get('DB_USERNAME', 'root'),
    // 'password' => Env::get('DB_PASSWORD', ''),
];
```

Environment variables in `.env`:

```env
DB_DRIVER=sqlite
DB_DATABASE=/path/to/database.sqlite

# Or for MySQL:
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=myapp
DB_USERNAME=root
DB_PASSWORD=secret
```

> **Note on Env:** `Env` is now an instantiable service, but static wrappers like `Env::get()` still work for convenience.

## Migrations

Migrations live in `database/migrations/` and are numbered sequentially.

### Creating a Migration

Create a new file: `database/migrations/0001_create_users_table.php`

```php
<?php

declare(strict_types=1);

use Fw\Database\Migration\Blueprint;
use Fw\Database\Migration\Migration;

final class CreateUsersTable extends Migration
{
    public function up(): void
    {
        $this->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->drop('users');
    }
}
```

### Running Migrations

```bash
# Run all pending migrations
php migrate.php migrate

# Check migration status
php migrate.php status

# Rollback last batch
php migrate.php rollback

# Rollback specific number of migrations
php migrate.php rollback 3

# Rollback all migrations
php migrate.php reset

# Rollback and re-run all migrations
php migrate.php refresh

# Drop all tables and re-run migrations
php migrate.php fresh

# Seed the database
php migrate.php seed
```

## Blueprint Methods

### Column Types

```php
$table->id();                           // Auto-incrementing ID
$table->string('name');                 // VARCHAR(255)
$table->string('code', 10);             // VARCHAR(10)
$table->text('content');                // TEXT
$table->integer('count');               // INTEGER
$table->bigInteger('views');            // BIGINT
$table->float('price');                 // FLOAT
$table->decimal('amount', 10, 2);       // DECIMAL(10,2)
$table->boolean('active');              // BOOLEAN
$table->datetime('published_at');       // DATETIME
$table->date('birth_date');             // DATE
$table->time('start_time');             // TIME
$table->timestamp('verified_at');       // TIMESTAMP
$table->json('metadata');               // JSON
$table->uuid('uuid');                   // UUID/CHAR(36)
```

### Column Modifiers

```php
$table->string('name')->nullable();           // Allow NULL
$table->string('status')->default('draft');   // Default value
$table->integer('order')->unsigned();         // Unsigned integer
$table->string('email')->unique();            // Unique constraint
```

### Indexes

```php
$table->index('email');                       // Single column index
$table->index(['user_id', 'created_at']);     // Composite index
$table->unique('email');                      // Unique index
$table->unique(['user_id', 'post_id']);       // Composite unique
```

### Foreign Keys

```php
$table->foreignId('user_id');                 // Creates user_id column

$table->foreign('user_id')
    ->references('id')
    ->on('users')
    ->cascadeOnDelete();                      // Foreign key constraint

// Or combined
$table->foreignId('user_id')
    ->constrained()                           // References users.id
    ->cascadeOnDelete();
```

### Timestamps & Soft Deletes

```php
$table->timestamps();                         // created_at, updated_at
$table->softDeletes();                        // deleted_at
```

## Migration Examples

### Posts Table

```php
final class CreatePostsTable extends Migration
{
    public function up(): void
    {
        $this->create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('content');
            $table->text('excerpt')->nullable();
            $table->datetime('published_at')->nullable();
            $table->integer('views')->default(0);
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->index('published_at');
        });
    }

    public function down(): void
    {
        $this->drop('posts');
    }
}
```

### Pivot Table

```php
final class CreatePostTagTable extends Migration
{
    public function up(): void
    {
        $this->create('post_tag', function (Blueprint $table) {
            $table->foreignId('post_id');
            $table->foreignId('tag_id');

            $table->foreign('post_id')
                ->references('id')
                ->on('posts')
                ->cascadeOnDelete();

            $table->foreign('tag_id')
                ->references('id')
                ->on('tags')
                ->cascadeOnDelete();

            $table->unique(['post_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        $this->drop('post_tag');
    }
}
```

### Adding Columns

```php
final class AddStatusToPostsTable extends Migration
{
    public function up(): void
    {
        $this->table('posts', function (Blueprint $table) {
            $table->string('status')->default('draft');
        });
    }

    public function down(): void
    {
        $this->table('posts', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
}
```

## Database Queries

### Using the Connection

```php
$db = $this->app->db;

// Select
$users = $db->query('SELECT * FROM users WHERE active = ?', [1]);

// Insert
$db->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['John', 'john@example.com']);

// Update
$db->execute('UPDATE users SET name = ? WHERE id = ?', ['Jane', 1]);

// Delete
$db->execute('DELETE FROM users WHERE id = ?', [1]);
```

### Transactions

```php
$db->transaction(function () use ($db) {
    $db->execute('INSERT INTO orders (user_id, total) VALUES (?, ?)', [1, 99.99]);
    $db->execute('UPDATE users SET order_count = order_count + 1 WHERE id = ?', [1]);
});
```

### Using Models

Prefer using Models over raw queries. In VibeFW v2.0.0, the **QueryBuilder is strictly immutable**. Every method returns a **CLONE** of the builder, so you must capture the result when building queries in stages:

```php
// Select
$users = User::where('active', true)->get();

// Building in stages (MUST re-assign variable)
$query = User::where('active', true);
if ($role) {
    $query = $query->where('role', $role);
}
$users = $query->get();
```

> **IMPORTANT:** Chaining works normally (e.g. `User::where('a', 1)->where('b', 2)->get()`), but conditional query building requires re-assignment (e.g. `$query = $query->where('status', 'active')`).

$user = User::find($id);

See [Models](models.md) for full documentation.

## Seeders

Create seeders in `database/seeders/`:

```php
<?php
// database/seeders/DatabaseSeeder.php

use App\Models\User;
use App\Models\Post;

return function () {
    // Create admin user
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => password_hash('password', PASSWORD_DEFAULT),
    ]);

    // Create sample posts
    for ($i = 1; $i <= 10; $i++) {
        Post::create([
            'user_id' => $admin->id,
            'title' => "Sample Post {$i}",
            'content' => "This is the content for post {$i}.",
            'published_at' => date('Y-m-d H:i:s'),
        ]);
    }
};
```

Run seeders:

```bash
php migrate.php seed
```

## Best Practices

1. **One migration per change** - Keep migrations focused
2. **Never modify existing migrations** - Create new ones instead
3. **Always implement down()** - Enable rollbacks
4. **Use foreign keys** - Maintain data integrity
5. **Index frequently queried columns** - Improve performance
6. **Use Models for data access** - Migrations for schema only
