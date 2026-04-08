# CLI Reference

All commands use the `php fw` entry point.

```bash
php fw                    # List all commands
php fw help <command>     # Help for a specific command
```

## Code Generators

```bash
php fw make:schema Post                              # JSON schema template
php fw make:resource --schema=app/Schemas/Post.json  # Full CRUD from schema
php fw add:field Post slug string --unique            # Add field to existing resource
php fw make:link Post Comment --hasMany               # Wire relationship
php fw make:test Post                                 # Generate tests from model

php fw make:model Post -m                             # Model + migration
php fw make:controller PostController -r              # Resource controller
php fw make:migration create_posts_table              # Migration
php fw make:middleware RateLimitMiddleware             # Middleware
php fw make:request StorePostRequest                  # FormRequest
php fw make:command SendReminders                     # Console command
php fw make:query GetPostById                         # CQRS query
php fw make:factory PostFactory                       # Test factory
php fw make:seeder DatabaseSeeder                     # Database seeder
php fw make:provider AppServiceProvider               # Service provider
php fw make:spa                                       # Vue 3 + TypeScript SPA
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

## Queue

```bash
php fw queue:work                 # Process queued jobs
```
