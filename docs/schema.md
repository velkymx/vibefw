# Schema-Driven Resource Generation

The schema-driven workflow is the primary way to build features. Define your resource once in JSON, and the CLI generates the model, migration, controller, form requests, factory, and views.

## Workflow

```bash
# 1. Create the schema template
php fw make:schema Post
# Creates: app/Schemas/Post.json

# 2. Edit the schema — add your fields, modifiers, constraints

# 3. Generate everything
php fw make:resource --schema=app/Schemas/Post.json
# Generates: Model, Migration, Controller, FormRequests, Factory, Views

# 4. Add the printed routes to config/routes.php

# 5. Run the migration
php fw migrate
```

## Schema Format

```json
{
  "$schema": "fw://resource-schema",
  "model": "Post",
  "table": "posts",
  "api": false,
  "fields": {
    "title": { "type": "string", "length": 255, "required": true },
    "content": { "type": "text", "required": true },
    "status": { "type": "string", "length": 20, "default": "draft" },
    "view_count": { "type": "integer", "default": 0 },
    "published_at": { "type": "timestamp", "nullable": true },
    "is_featured": { "type": "boolean", "default": false },
    "user_id": { "type": "foreignId", "constrained": true, "onDelete": "cascade" }
  }
}
```

### Top-Level Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `model` | string | Yes | PascalCase model name (e.g. `Post`, `BlogPost`) |
| `table` | string | Yes | snake_case table name (e.g. `posts`, `blog_posts`) |
| `api` | boolean | No | `true` = API-only (skips views, uses JSON responses). Default `false` |
| `fields` | object | Yes | Field definitions — at least one required |

### Field Types

| Type | PHP Cast | Migration Column | Faker |
|------|----------|-----------------|-------|
| `string` | — | `VARCHAR(255)` | `fake()->sentence()` |
| `text` | — | `TEXT` | `fake()->paragraphs(3, true)` |
| `integer` | `int` | `INTEGER` | `fake()->numberBetween(1, 1000)` |
| `boolean` | `bool` | `BOOLEAN` | `fake()->boolean()` |
| `timestamp` | `datetime` | `TIMESTAMP` | `fake()->dateTime()` |
| `date` | `datetime` | `DATE` | `fake()->date()` |
| `decimal` | `float` | `DECIMAL` | `fake()->randomFloat(2, 0, 9999)` |
| `foreignId` | `int` | `UNSIGNED BIGINT` | `1` |
| `json` | `array` | `JSON` | `[]` |

### Field Modifiers

| Modifier | Applies To | Description |
|----------|-----------|-------------|
| `required` | All | Adds `new Required` rule to FormRequest |
| `nullable` | All | Adds `->nullable()` to migration column |
| `unique` | All | Adds `->unique()` to migration + `new Unique` to FormRequest |
| `default` | All | Adds `->default(value)` to migration column |
| `index` | All | Adds `->index()` to migration column |
| `length` | `string` | Column length (default 255) |
| `constrained` | `foreignId` only | Adds `->constrained()` and `new Exists` rule to FormRequest |
| `onDelete` | `foreignId` only | `"cascade"` → `->cascadeOnDelete()` |

## What Gets Generated

Given `php fw make:resource --schema=app/Schemas/Post.json`:

| File | Description |
|------|-------------|
| `app/Models/Post.php` | Model with `$table`, `$fillable`, `$casts` — see [models.md](models.md) |
| `database/migrations/{n}_create_posts_table.php` | Migration with all columns — see [database.md](database.md) |
| `app/Controllers/PostController.php` | Resource controller (web or API) — see [controllers.md](controllers.md) |
| `app/Requests/StorePostRequest.php` | FormRequest with typed rules — see [validation.md](validation.md) |
| `app/Requests/UpdatePostRequest.php` | FormRequest with typed rules — see [validation.md](validation.md) |
| `database/factories/PostFactory.php` | Factory with Faker definitions — see [testing.md](testing.md) |
| `app/Views/posts/index.php` | Index view (web only) — see [views.md](views.md) |
| `app/Views/posts/create.php` | Create form (web only) — see [views.md](views.md) |
| `app/Views/posts/edit.php` | Edit form (web only) — see [views.md](views.md) |
| `app/Views/posts/show.php` | Show view (web only) — see [views.md](views.md) |

The command also prints the exact route block to copy into `config/routes.php` — see [routing.md](routing.md).

## API Resources

Set `"api": true` to generate an API controller that returns JSON instead of views:

```json
{
  "$schema": "fw://resource-schema",
  "model": "Post",
  "table": "posts",
  "api": true,
  "fields": {
    "title": { "type": "string", "required": true },
    "content": { "type": "text", "required": true }
  }
}
```

API mode:
- Controller uses `$this->json()` instead of `$this->view()`
- No views are generated
- Validation failures return `422` with `{"errors": {...}}`
- Not found returns `404` with `{"error": "..."}`

## Adding Fields to an Existing Resource

```bash
php fw add:field Post slug string --unique --index
php fw add:field Post category_id foreignId --constrained
php fw add:field Post published_at timestamp --nullable
php fw add:field Post view_count integer --default=0
```

**Options:**

| Option | Description |
|--------|-------------|
| `--nullable` | Make the column nullable |
| `--unique` | Add unique constraint |
| `--constrained` | Add foreign key constraint (`foreignId` only) |
| `--default=value` | Set a default value |
| `--index` | Add an index |

This command:
1. Creates a new migration (`add_{field}_to_{table}_table`)
2. Updates the model's `$fillable` and `$casts`
3. Updates `app/Schemas/{Model}.json` if it exists

Run `php fw migrate` after to apply the migration.

## Wiring Relationships

```bash
php fw make:link Post Comment --hasMany      # Post hasMany Comments
php fw make:link User Profile --hasOne       # User hasOne Profile
php fw make:link Post Tag --manyToMany       # Post manyToMany Tags (pivot table)
```

This generates migrations, updates both model files, and adds `$fillable` entries.

## Complete Example

### 1. Create schema

```bash
php fw make:schema BlogPost
```

### 2. Edit `app/Schemas/BlogPost.json`

```json
{
  "$schema": "fw://resource-schema",
  "model": "BlogPost",
  "table": "blog_posts",
  "api": false,
  "fields": {
    "title": { "type": "string", "length": 200, "required": true },
    "slug": { "type": "string", "length": 200, "unique": true, "index": true },
    "content": { "type": "text", "required": true },
    "excerpt": { "type": "text", "nullable": true },
    "published_at": { "type": "timestamp", "nullable": true },
    "view_count": { "type": "integer", "default": 0 },
    "user_id": { "type": "foreignId", "constrained": true, "onDelete": "cascade" }
  }
}
```

### 3. Generate

```bash
php fw make:resource --schema=app/Schemas/BlogPost.json
```

Output:
```
✓ Model created: app/Models/BlogPost.php
✓ Migration created: database/migrations/0005_create_blog_posts_table.php
✓ Controller created: app/Controllers/BlogPostController.php
✓ Request created: app/Requests/StoreBlogPostRequest.php
✓ Request created: app/Requests/UpdateBlogPostRequest.php
✓ Factory created: database/factories/BlogPostFactory.php
✓ Views created: app/Views/blog_posts/

Add these routes to config/routes.php:

    $router->get('/blog-posts', [App\Controllers\BlogPostController::class, 'index']);
    $router->get('/blog-posts/create', [App\Controllers\BlogPostController::class, 'create']);
    $router->post('/blog-posts', [App\Controllers\BlogPostController::class, 'store']);
    $router->get('/blog-posts/{id}', [App\Controllers\BlogPostController::class, 'show']);
    $router->get('/blog-posts/{id}/edit', [App\Controllers\BlogPostController::class, 'edit']);
    $router->put('/blog-posts/{id}', [App\Controllers\BlogPostController::class, 'update']);
    $router->delete('/blog-posts/{id}', [App\Controllers\BlogPostController::class, 'destroy']);
```

### 4. Migrate and run

```bash
php fw migrate
php fw serve
```

## Schema Validation

The schema is validated before any files are generated. Common errors:

```
Schema validation failed: Field "user_id": "constrained" is only valid for foreignId fields.
Schema validation failed: Field "status": unknown type "enum". Valid types: string, text, integer, ...
Schema validation failed: Missing required "model" field.
```

Fix the JSON and re-run — no partial state is created on validation failure.
