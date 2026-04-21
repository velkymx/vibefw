# START HERE

Best practices for this part of the codebase are to use the following CLI commands.

- `php fw` — list every registered command, grouped by namespace
- `php fw help <command>` — show the signature, options, and arguments for a single command
- `php fw <command> --help` — same thing, alternate syntax

The `php fw` registry is the authoritative source for what commands exist. This doc is a curated reference; when the two disagree, trust `php fw`.

# BEWARE

Only read past here if you are unable to use the CLI.

# CLI Reference

All commands use the `php fw` entry point.

```bash
php fw                    # List all commands
php fw help <command>     # Help for a specific command
```

## Code Generators

### Schema-Driven (preferred)

```bash
php fw make:schema Post                               # JSON schema template → app/Schemas/Post.json
php fw make:resource --schema=app/Schemas/Post.json  # Full CRUD: model + migration + controller + requests + factory + views
```

See [schema.md](schema.md) for the full schema format and field type reference.

### Individual Generators

**`make:model`**
```bash
php fw make:model Post          # app/Models/Post.php
php fw make:model Post -m       # Model + matching migration (--migration)
```

**`make:controller`**
```bash
php fw make:controller PostController        # Basic controller
php fw make:controller PostController -r     # Resource controller with index/create/store/show/edit/update/destroy (--resource)
php fw make:controller Api/PostController -r # Namespaced (creates app/Controllers/Api/)
```

**`make:migration`**
```bash
php fw make:migration create_posts_table           # Auto-detects table from name (create_*_table pattern)
php fw make:migration add_status_to_posts          # Generic alter migration
php fw make:migration create_posts --create=posts  # Explicit table name via --create
```

**`add:field`** — adds field to existing resource (migration + model + schema)
```bash
php fw add:field Post slug string          # Basic field
php fw add:field Post slug string --unique --index
php fw add:field Post category_id foreignId --constrained
php fw add:field Post published_at timestamp --nullable
php fw add:field Post score decimal --default=0
```
Options: `--nullable`, `--unique`, `--constrained` (foreignId only), `--default=value`, `--index`
Valid types: `string`, `text`, `integer`, `boolean`, `timestamp`, `date`, `decimal`, `foreignId`, `json`

**`make:link`** — wires relationships between two models
```bash
php fw make:link Post Comment --hasMany     # Post hasMany Comments
php fw make:link User Profile --hasOne      # User hasOne Profile
php fw make:link Post Tag --manyToMany      # Pivot table + both models updated
```

**Other generators**
```bash
php fw make:request StorePostRequest        # app/Requests/StorePostRequest.php
php fw make:middleware RateLimitMiddleware  # app/Middleware/RateLimitMiddleware.php
php fw make:provider AppServiceProvider    # app/Providers/AppServiceProvider.php
php fw make:command SendReminders          # app/Console/SendReminders.php
php fw make:query GetPostById              # app/Queries/GetPostById.php
php fw make:factory PostFactory            # database/factories/PostFactory.php
php fw make:seeder DatabaseSeeder          # database/seeders/DatabaseSeeder.php
php fw make:test Post                      # Unit + feature tests from existing model
php fw make:spa                            # Vue 3 + TypeScript SPA scaffold
```

## Schema-Driven Workflow

The primary way to build features:

```bash
# 1. Create schema template
php fw make:schema Post

# 2. Edit app/Schemas/Post.json — add fields, types, modifiers

# 3. Generate everything from schema
php fw make:resource --schema=app/Schemas/Post.json
# Generates: Model, Migration, Controller, FormRequests, Views, Factory

# 4. Add fields later
php fw add:field Post category_id foreignId --constrained

# 5. Wire relationships
php fw make:link Post Comment --hasMany

# 6. Generate tests
php fw make:test Post
```

## Database

```bash
php fw migrate                    # Run pending migrations
php fw migrate:status             # Show migration status
php fw migrate:rollback           # Rollback last batch
php fw migrate:rollback --step=3  # Rollback N migrations
php fw migrate:fresh              # Drop all + re-migrate
php fw migrate:fresh --seed       # Fresh + seed
php fw db:seed                    # Run seeders
php fw db:status                  # Show database connection + pending migrations
```

## Inspection & Debugging

```bash
php fw model:inspect Post         # Table, columns, fillable, casts, relations, rows
php fw route:for post             # Routes matching a feature topic
php fw routes:list                # All registered routes
php fw routes:list --method=GET   # Filter by HTTP method
php fw test:for post              # Find test files for a feature
php fw error:explain "message"    # Parse error, suggest fix
```

## AI Context

```bash
php fw ai:map                     # Generate project map (ai-app-map.md)
php fw ai:context posts           # Dump all files for a feature
php fw ai:context posts --compact # Without comments
php fw ai:context posts --json    # Machine-readable
php fw ai:next                    # Suggest next step
```

## Validation & Testing

```bash
php fw check                      # Conventions + architecture + PHPStan
php fw fix                        # Auto-correct violations
php fw test                       # Run all tests
php fw test --filter=PostTest     # Run specific tests
php fw test --testsuite=unit      # Run test suite
php fw test --coverage            # With coverage
php fw validate:all               # Config + security + style + analysis
php fw validate:security          # Security scan
php fw validate:config            # Config validation
```

## Development

```bash
php fw serve                      # Dev server (localhost:8000)
php fw serve --port=8080          # Custom port
php fw setup                      # Initialize project (env, keys, database)
php fw cache:clear                # Clear all caches
php fw sync:env                   # Sync .env with .env.example
```

## Production

```bash
php fw optimize                   # Cache routes + config
php fw optimize:clear             # Clear all caches
php fw config:cache               # Cache configuration
php fw config:clear               # Clear config cache
php fw route:cache                # Cache routes
php fw route:clear                # Clear route cache
```

## AUX (Agent Tools)

```bash
php fw aux:list                                              # List all registered AUX tools
php fw aux:call process_ticket_queue --input '{"queue_id":1}' # Invoke a tool with JSON input
php fw aux:call public_status                                # Invoke with default empty input
php fw aux:schema process_ticket_queue                       # Dump tool's MCP shape as JSON
php fw serve:mcp                                             # Start MCP server (stdio)
```

See [aux.md](aux.md) for the full AUX guide and [mcp.md](mcp.md) for MCP client setup.

## Queue

```bash
php fw queue:work                 # Process queued jobs
```
