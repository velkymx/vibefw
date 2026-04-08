# Database & Migrations

## Configuration

Configure in `config/database.php` and `.env`:

```env
# SQLite (default)
DB_DRIVER=sqlite
DB_DATABASE=/path/to/database.sqlite

# MySQL
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=myapp
DB_USERNAME=root
DB_PASSWORD=secret
DB_PERSISTENT=false
```

## Migrations

Migrations live in `database/migrations/`.

### Creating Migrations

```bash
php fw make:migration create_posts_table     # Auto-detects table name
php fw make:migration add_status_to_posts    # Generic migration
```

```php
<?php

declare(strict_types=1);

use Fw\Database\Migration;
use Fw\Database\Schema;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('posts', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('content');
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->integer('view_count')->default(0);
            $table->timestamps();

            $table->index('published_at');
            $table->index(['user_id', 'published_at']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('posts');
    }
};
```

### Running Migrations

```bash
php fw migrate                    # Run pending migrations
php fw migrate:status             # Show migration status
php fw migrate:rollback           # Rollback last batch
php fw migrate:rollback --step=3  # Rollback N migrations
php fw migrate:fresh              # Drop all + re-migrate
php fw migrate:fresh --seed       # Fresh + seed
php fw db:status                  # Show database state
```

## Column Types

```php
$table->id();                           // Auto-incrementing ID
$table->string('name');                 // VARCHAR(255)
$table->string('code', 10);            // VARCHAR(10)
$table->text('content');               // TEXT
$table->integer('count');              // INTEGER
$table->bigInteger('views');           // BIGINT
$table->float('price');                // FLOAT
$table->decimal('amount', 10, 2);     // DECIMAL(10,2)
$table->boolean('active');             // BOOLEAN
$table->datetime('published_at');      // DATETIME
$table->date('birth_date');            // DATE
$table->time('start_time');           // TIME
$table->timestamp('verified_at');      // TIMESTAMP
$table->json('metadata');              // JSON
$table->uuid('uuid');                  // UUID/CHAR(36)
```

## Column Modifiers

```php
$table->string('name')->nullable();
$table->string('status')->default('draft');
$table->integer('order')->unsigned();
$table->string('email')->unique();
```

## Indexes

```php
$table->index('email');                       // Single column
$table->index(['user_id', 'created_at']);     // Composite
$table->unique('email');                      // Unique index
$table->unique(['user_id', 'post_id']);       // Composite unique
```

## Foreign Keys

```php
// Short form
$table->foreignId('user_id')->constrained()->cascadeOnDelete();

// Explicit form
$table->foreignId('user_id');
$table->foreign('user_id')
    ->references('id')
    ->on('users')
    ->cascadeOnDelete();
```

## Timestamps & Soft Deletes

```php
$table->timestamps();     // created_at, updated_at
$table->softDeletes();    // deleted_at
```

## Migration Examples

### Pivot Table

```php
return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('post_tag', function ($table) {
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->unique(['post_id', 'tag_id']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('post_tag');
    }
};
```

### Adding Columns

```bash
php fw add:field Post category_id foreignId --constrained
```

Or manually:

```php
return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->table('posts', function ($table) {
            $table->string('status')->default('draft');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->table('posts', function ($table) {
            $table->dropColumn('status');
        });
    }
};
```

## Raw Queries

Prefer Models over raw queries. When needed:

```php
$db = $this->app->db;

$users = $db->query('SELECT * FROM users WHERE active = ?', [1]);
$db->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['John', 'john@example.com']);
```

### Transactions

```php
$db->transaction(function () use ($db) {
    $db->execute('INSERT INTO orders (user_id, total) VALUES (?, ?)', [1, 99.99]);
    $db->execute('UPDATE users SET order_count = order_count + 1 WHERE id = ?', [1]);
});
```

## Seeders

```bash
php fw make:seeder DatabaseSeeder
php fw db:seed
```

```php
<?php

use App\Models\User;
use App\Models\Post;

return function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => password_hash('password', PASSWORD_DEFAULT),
    ]);

    for ($i = 1; $i <= 10; $i++) {
        Post::create([
            'user_id' => $admin->id,
            'title' => "Sample Post {$i}",
            'content' => "Content for post {$i}.",
            'published_at' => date('Y-m-d H:i:s'),
        ]);
    }
};
```

## Database Identifier Quoting

MySQL uses backticks, SQLite/PostgreSQL use double quotes. Always use `$this->quote()` or `$db->quoteIdentifier()` for table/column names in raw queries:

```php
// Correct
$this->execute('DROP TABLE ' . $this->quote('users'));

// Wrong — breaks MySQL
$this->execute('DROP TABLE "users"');
```
