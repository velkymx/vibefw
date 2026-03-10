<?php

declare(strict_types=1);

namespace Fw\Core;

/**
 * Base class for service providers.
 *
 * Service providers are the central place to configure and bootstrap
 * framework and application services. Each provider has two lifecycle
 * methods:
 *
 * - register(): Called during application bootstrap. Register bindings
 *   and services in the container. Do not resolve dependencies here.
 *
 * - boot(): Called after all providers are registered. Safe to resolve
 *   dependencies and perform initialization that depends on other services.
 *
 * Example:
 *     class CacheServiceProvider extends ServiceProvider
 *     {
 *         public function register(): void
 *         {
 *             $this->container->singleton(CacheInterface::class, fn() => new RedisCache(
 *                 $this->app->config('cache.redis.host')
 *             ));
 *         }
 *
 *         public function boot(): void
 *         {
 *             // Safe to resolve services here
 *             $cache = $this->container->get(CacheInterface::class);
 *             $cache->connect();
 *         }
 *     }
 */
abstract class ServiceProvider
{
    protected Container $container;

    protected Application $app;

    public function __construct(Application $app, Container $container)
    {
        $this->app = $app;
        $this->container = $container;
    }

    /**
     * Register services in the container.
     *
     * Called during application bootstrap. Use this method to bind
     * interfaces to implementations or register singletons.
     *
     * Do not resolve dependencies from the container here as other
     * providers may not have registered their services yet.
     */
    abstract public function register(): void;

    /**
     * Bootstrap services after registration.
     *
     * Called after all providers have registered their services.
     * Safe to resolve dependencies from the container here.
     *
     * Use this for initialization that depends on other services,
     * subscribing to events, or configuring resolved services.
     */
    public function boot(): void
    {
        // Override in subclass if needed
    }
}
