<?php

declare(strict_types=1);

namespace Fw\Providers;

use Fw\Cache\Cache;
use Fw\Cache\CacheInterface;
use Fw\Core\Env;
use Fw\Core\ServiceProvider;

/**
 * Cache Service Provider.
 *
 * Registers the caching system with layered L1/L2 support.
 */
class CacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $cachePath = BASE_PATH . '/storage/cache';
        $cacheDriver = Env::get('CACHE_DRIVER', 'auto'); // Default to 'auto'

        $l2Store = null;
        if ($cacheDriver === 'file') {
            $l2Store = new \Fw\Cache\FileCache($cachePath);
        } elseif ($cacheDriver === 'opcache') {
            $l2Store = new \Fw\Cache\OpcacheCache($cachePath);
        } elseif ($cacheDriver === 'apcu' && \Fw\Cache\ApcuCache::isAvailable()) {
            $l2Store = new \Fw\Cache\ApcuCache();
        } elseif ($cacheDriver === 'auto') {
            if (\Fw\Cache\ApcuCache::isAvailable()) {
                $l2Store = new \Fw\Cache\ApcuCache();
            } else {
                $l2Store = new \Fw\Cache\FileCache($cachePath);
            }
        }

        $cache = new Cache($l2Store, $cachePath);

        $this->container->singleton(Cache::class, fn () => $cache);
        $this->container->singleton(CacheInterface::class, fn () => $cache);
    }

    public function boot(): void
    {
        // Make cache available on application
    }
}
