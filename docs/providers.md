# START HERE

Best practices for this part of the codebase are to use the following CLI commands.

- `php fw make:provider AppServiceProvider` — scaffold a new service provider under `app/Providers/`
- `php fw check` — confirms the provider is registered in `config/providers.php` and doesn't violate layer rules

# BEWARE

Only read past here if you are unable to use the CLI.

# Service Providers

Service providers bootstrap and configure services in your application. They're the central place to register bindings, event listeners, and other setup logic.

## How Providers Work

Providers have two lifecycle phases:

1. **Register** - Bind services to the container. Don't resolve dependencies here.
2. **Boot** - Called after all providers are registered. Safe to resolve dependencies.

```php
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind services - runs first for ALL providers
        $this->container->singleton(PaymentGateway::class, fn() => new StripeGateway());
    }

    public function boot(): void
    {
        // Initialize services - runs after ALL providers are registered
        $gateway = $this->container->get(PaymentGateway::class);
        $gateway->setApiKey(config('services.stripe.key'));
    }
}
```

## Creating a Provider

```bash
php fw make:provider AppServiceProvider
```

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Fw\Core\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register bindings
    }

    public function boot(): void
    {
        // Initialize services
    }
}
```

## Registering Providers

Add providers to `config/providers.php`:

```php
return [
    // Framework providers
    Fw\Providers\EventServiceProvider::class,
    Fw\Providers\BusServiceProvider::class,
    Fw\Providers\MiddlewareServiceProvider::class,
    Fw\Providers\CacheServiceProvider::class,

    // Application providers
    App\Providers\AppServiceProvider::class,
    App\Providers\EventServiceProvider::class,
];
```

Providers are registered and booted in order.

## Container Bindings

### Bind Interface to Implementation

```php
public function register(): void
{
    $this->container->bind(
        PaymentGatewayInterface::class,
        StripeGateway::class
    );
}
```

### Singleton

```php
public function register(): void
{
    $this->container->singleton(
        CacheInterface::class,
        fn() => new FileCache(BASE_PATH . '/storage/cache')
    );
}
```

### Instance

```php
public function register(): void
{
    $config = require BASE_PATH . '/config/app.php';
    $this->container->instance('config', $config);
}
```

### Factory

```php
public function register(): void
{
    $this->container->bind(Logger::class, function ($container) {
        return new Logger(
            $container->get('config')['logging']['path']
        );
    });
}
```

## Accessing the Application

Providers have access to the Application and Container:

```php
class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Access application
        $debug = $this->app->config('app.debug');

        // Access container
        $cache = $this->container->get(CacheInterface::class);

        // Access other services
        $router = $this->container->get(Router::class);
    }
}
```

## Provider Examples

### Event Service Provider

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Fw\Core\ServiceProvider;
use App\Events\UserRegistered;
use App\Listeners\SendWelcomeEmail;
use App\Listeners\CreateUserProfile;

class EventServiceProvider extends ServiceProvider
{
    protected array $listen = [
        UserRegistered::class => [
            SendWelcomeEmail::class,
            CreateUserProfile::class,
        ],
    ];

    public function boot(): void
    {
        $dispatcher = $this->container->get(EventDispatcher::class);

        foreach ($this->listen as $event => $listeners) {
            foreach ($listeners as $listener) {
                $dispatcher->listen($event, $listener);
            }
        }
    }
}
```

### Cache Service Provider

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Fw\Core\ServiceProvider;
use Fw\Cache\CacheInterface;
use Fw\Cache\FileCache;
use Fw\Cache\RedisCache;

class CacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(CacheInterface::class, function () {
            $driver = $this->app->config('cache.driver', 'file');

            return match ($driver) {
                'redis' => new RedisCache($this->app->config('cache.redis')),
                default => new FileCache(BASE_PATH . '/storage/cache'),
            };
        });
    }
}
```

### Mail Service Provider

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Fw\Core\ServiceProvider;

class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Mailer::class, function () {
            return new Mailer([
                'host' => $this->app->config('mail.host'),
                'port' => $this->app->config('mail.port'),
                'username' => $this->app->config('mail.username'),
                'password' => $this->app->config('mail.password'),
            ]);
        });
    }

    public function boot(): void
    {
        $mailer = $this->container->get(Mailer::class);
        $mailer->setDefaultFrom($this->app->config('mail.from'));
    }
}
```

### Auth Service Provider

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Fw\Core\ServiceProvider;
use Fw\Auth\Auth;
use Fw\Auth\SessionGuard;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Auth::class, function () {
            return new Auth(new SessionGuard());
        });
    }

    public function boot(): void
    {
        // Share authenticated user with all views
        $auth = $this->container->get(Auth::class);
        $this->app->view->share('currentUser', $auth->user());
    }
}
```

### CQRS Bus Handlers

To register command/query handlers explicitly instead of relying on naming convention — see [cqrs.md](cqrs.md):

```php
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

## Deferred Providers

For providers that shouldn't load on every request:

```php
class HeavyServiceProvider extends ServiceProvider
{
    protected bool $defer = true;

    protected array $provides = [
        HeavyService::class,
    ];

    public function register(): void
    {
        $this->container->singleton(HeavyService::class, fn() => new HeavyService());
    }
}
```

Deferred providers only load when their services are requested.

## Best Practices

1. **Register phase is for binding only** - Don't resolve dependencies in `register()`
2. **Boot phase for initialization** - Resolve and configure services in `boot()`
3. **Keep providers focused** - One provider per concern
4. **Use singletons wisely** - For services that should have one instance
5. **Order matters** - Framework providers before application providers
6. **Use interfaces** - Bind interfaces to implementations for flexibility
