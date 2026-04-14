<?php

declare(strict_types=1);

namespace Fw\Middleware;

use Fw\Cache\CacheInterface;
use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Core\Response;

/**
 * Rate limiting middleware using cache backend.
 *
 * Uses the configured cache driver (Redis, APCu, file) for atomic
 * increment operations. Much faster than filesystem-based rate limiting.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    private const string CACHE_PREFIX = 'ratelimit:';

    private Application $app;

    private CacheInterface $cache;

    private int $maxRequests;

    private int $windowSeconds;

    public function __construct(Application $app, CacheInterface $cache)
    {
        $this->app = $app;
        $this->cache = $cache;
        $this->maxRequests = (int) $app->config('app.rate_limit.max', 60);
        $this->windowSeconds = (int) $app->config('app.rate_limit.window', 60);
    }

    public function handle(Request $request, callable $next): Response|string|array
    {
        $key = $this->getKey($request);

        // Atomic increment — avoids the read/check/write race condition
        $ttl = $this->windowSeconds + 60;
        $current = $this->cache->increment($key, 1, $ttl);

        // increment() returns false only on driver failure; treat as unlimited
        if ($current === false) {
            // Log security event — cache outage silently disables rate limiting.
            // An attacker causing cache failure (OOM, poisoned cache) would
            // bypass all rate limits with no visibility.
            error_log(sprintf(
                'SECURITY: Rate limit cache failure — limiting disabled for IP %s (key: %s)',
                $request->ip(),
                $key
            ));
            return $next($request);
        }

        if ($current > $this->maxRequests) {
            return $this->tooManyRequests($current);
        }

        $response = $next($request);

        if ($response instanceof Response) {
            return $this->addRateLimitHeaders($response, $current);
        }

        return $response;
    }

    private function getKey(Request $request): string
    {
        $identifier = $request->ip();
        // Use intdiv() instead of floor(time() / x) to avoid potential integer overflow
        // on 32-bit systems where floor() returns float that may overflow on (int) cast
        $window = intdiv(time(), $this->windowSeconds);
        // Use SHA256 for cryptographic collision resistance to prevent rate limit bypass
        return self::CACHE_PREFIX . hash('sha256', $identifier . ':' . $window);
    }

    private function tooManyRequests(int $current): Response
    {
        $response = $this->app->response->setStatus(429);
        $response = $this->addRateLimitHeaders($response, $current);
        return $response->header('Retry-After', (string) $this->windowSeconds);
    }

    private function addRateLimitHeaders(Response $response, int $current): Response
    {
        $remaining = max(0, $this->maxRequests - $current);

        return $response->header('X-RateLimit-Limit', (string) $this->maxRequests)
            ->header('X-RateLimit-Remaining', (string) $remaining)
            ->header('X-RateLimit-Reset', (string) (time() + $this->windowSeconds));
    }
}
