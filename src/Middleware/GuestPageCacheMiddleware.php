<?php

declare(strict_types=1);

namespace Fw\Middleware;

use Fw\Cache\CacheInterface;
use Fw\Cache\GuestCacheKey;
use Fw\Core\Request;
use Fw\Core\Response;

/**
 * Guest Page Cache Middleware
 *
 * SECURITY: Only caches responses for users WITHOUT active sessions.
 *
 * Safe because:
 * - No session cookie = definitely not authenticated
 * - Guest pages don't contain CSRF tokens (they're in auth-only sections)
 * - No user-specific data to leak
 *
 * Users with session cookies always bypass this cache and get fresh responses.
 */
class GuestPageCacheMiddleware implements MiddlewareInterface
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

        // SECURITY: Never cache for users with session cookies.
        // They might be authenticated or have session-specific state.
        // Request::hasCookie() reads request-scoped state (not the
        // cookie superglobal) so concurrent requests in worker/Fiber
        // mode don't leak each other's session presence.
        if ($request->hasCookie(session_name())) {
            return $next($request);
        }

        $cacheKey = GuestCacheKey::build(
            $request->method,
            (string) $request->header('host', ''),
            $request->fullUri,
        );

        // Early check handled in Application::tryServeFromCache()
        // This is a fallback for when that didn't trigger

        // Execute the request
        $response = $next($request);

        // Cache successful responses for future guest requests
        if ($response instanceof Response && $response->getStatusCode() === 200) {
            $this->cache->set($cacheKey, $response->getBody(), $this->ttl);
        } elseif (is_string($response)) {
            $this->cache->set($cacheKey, $response, $this->ttl);
        }

        return $response;
    }
}
