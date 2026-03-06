# Upgrade Guide: 1.0.5 to 2.0.0

- [High Impact Changes](#high-impact-changes)
    - [Response Lifecycle](#response-lifecycle)
    - [Query Builder Immutability](#query-builder-immutability)
- [Medium Impact Changes](#medium-impact-changes)
    - [Request Properties](#request-properties)
    - [Fiber-Scoped Singletons](#fiber-scoped-singletons)
    - [Queue & Worker Refactor](#queue--worker-refactor)
    - [Resettable Interface](#resettable-interface)
- [Low Impact Changes](#low-impact-changes)
    - [Environment Handling](#environment-handling)

## High Impact Changes

### Response Lifecycle

**Likelihood of Impact: High**

In VibeFW 2.0, the `Response` object has been transformed into a pure "Value Object" without side effects. The `emit()`, `send()`, and `exit()` methods have been removed to support high-concurrency runtimes where the process must stay alive between requests.

The `HttpKernel::handle()` method now **returns** a `Response` object instead of emitting it to the buffer. If you were manually interacting with the response lifecycle in your entry points or middleware, you must update your code to capture and return the response.

**Action Required:**
Update your `public/index.php` or custom entry points to use the new `SapiEmitter`:

```php
// Old (v1.0.5)
$app->run();

// New (v2.0.0)
$response = $app->getKernel()->handle($request);
(new SapiEmitter())->emit($response);
```

### Query Builder Immutability

**Likelihood of Impact: High**

To prevent silent state contamination during complex or branched queries, the `QueryBuilder` is now fully immutable. Every method call (`where`, `select`, `limit`, etc.) now returns a **cloned instance** of the builder.

**Action Required:**
Ensure that you are re-assigning the builder variable when building queries in stages. Traditional "fluent" chaining is unaffected, but branched logic must be updated:

```php
// Old (v1.0.5)
$query = $db->table('users');
if ($activeOnly) {
    $query->where('active', 1); // This modified $query in place
}
return $query->get();

// New (v2.0.0)
$query = $db->table('users');
if ($activeOnly) {
    $query = $query->where('active', 1); // Must re-assign the cloned result
}
return $query->get();
```

## Medium Impact Changes

### Request Properties

**Likelihood of Impact: Medium**

The `Request` object properties (`query`, `post`, `server`, `files`, `headers`) are now `readonly`. Additionally, the constructor has been updated to support manual dependency injection, allowing the framework to run in environments where superglobals are stale or unavailable.

**Action Required:**
If your application was manually overwriting properties on the `Request` object (e.g., `$request->query = [...]`), you must instead use the constructor to inject the desired state or utilize Middleware to transform the request data.

### Fiber-Scoped Singletons

**Likelihood of Impact: Medium**

The DI `Container` now defaults to "Fiber-local" singletons. This means that a service registered as a singleton will have **one instance per Fiber/Request** rather than one instance per process. This is a critical security feature that prevents data leakage between concurrent requests.

**Action Required:**
If you have a truly global service that must be shared across all concurrent requests (like an `EventLoop` or a `SharedCache`), you must explicitly mark it as global during registration:

```php
// Fiber-scoped (Default)
$container->singleton(MyService::class);

// Truly Global (Shared across all requests)
$container->singleton(GlobalService::class, global: true);
```

### Queue & Worker Refactor

**Likelihood of Impact: Medium**

The `Queue` and `Worker` classes are no longer static singletons. They are now standard services managed by the DI container and registered via the `QueueServiceProvider`.

**Action Required:**
If you were using `Queue::getInstance()` or manually configuring the worker in `worker.php`, you must update your code to resolve these services from the container:

```php
// Old (v1.0.5)
Queue::configure($config);
$queue = Queue::getInstance();

// New (v2.0.0)
$app = Application::getInstance();
$queue = $app->getContainer()->get(Queue::class);
```

The `Worker` constructor now also requires the `Container` to be passed in, enabling it to automatically reset state between jobs.

### Resettable Interface

**Likelihood of Impact: Medium**

The `ResettableInterface` has been moved from the `Fw\Core` namespace to `Fw\Support` to resolve circular dependency issues between the core and service layers.

**Action Required:**
Update any custom services that implemented this interface to use the new namespace:

```php
// Old
use Fw\Core\ResettableInterface;

// New
use Fw\Support\ResettableInterface;
```

## Low Impact Changes

### Environment Handling

**Likelihood of Impact: Low**

The `Env` class is no longer a static-only utility. It is now an instantiable service managed by the DI container. This allows multiple application instances to exist in the same process with different environment configurations.

**Action Required:**
While static wrappers (`Env::get()`, `Env::load()`) have been preserved for backward compatibility, it is recommended to inject the `Env` instance into your services via the container for better testability.
