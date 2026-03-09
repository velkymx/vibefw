<?php

declare(strict_types=1);

namespace Fw\Middleware;

use Fw\Cache\CacheInterface;
use Fw\Core\Request;
use Fw\Core\Response;

/**
 * Page Cache Middleware - OPT-IN ONLY
 *
 * SECURITY WARNING: Only use on routes that return truly static content.
 * NEVER cache pages containing:
 * - CSRF tokens
 * - Session data
 * - User-specific content
 * - Personalized data
 *
 * Usage in routes:
 *   $router->get('/api/products', [ProductController::class, 'list'])
 *       ->middleware('page_cache:300'); // Cache for 300 seconds
 *
 * The middleware parameter is the TTL in seconds.
 */
class PageCacheMiddleware implements MiddlewareInterface
{
    private CacheInterface $cache;

    private int $ttl;

    public function __construct(\Fw\Core\Application $app, int|string $ttl = 60)
    {
        $this->cache = $app->getContainer()->get(CacheInterface::class);
        $this->ttl = (int) $ttl;
    }

    public function handle(Request $request, callable $next): Response|string|array
    {
        // Only cache GET requests
        if ($request->method !== 'GET') {
            return $next($request);
        }

        $cacheKey = 'page:static:' . md5($request->uri);

        // Check cache (early check in Application may have already served this)
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return new Response($cached);
        }

        // Execute the request
        $response = $next($request);

        // Cache successful responses
        if ($response instanceof Response && $response->getStatusCode() === 200) {
            $this->cache->set($cacheKey, $response->getBody(), $this->ttl);
        } elseif (is_string($response)) {
            $this->cache->set($cacheKey, $response, $this->ttl);
        }

        return $response;
    }
}
