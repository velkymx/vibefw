<?php

declare(strict_types=1);

namespace Fw\Cache;

/**
 * Single source of truth for guest page cache keys.
 *
 * Both the kernel's read path and any middleware that writes to the
 * guest cache must produce identical keys for identical requests, or
 * writes are silently dead. The previous implementations diverged on
 * algorithm (sha256 vs md5) and URL shape (normalized path+query vs
 * raw uri), and neither included the host — two vhosts on the same
 * path could serve each other's bodies.
 *
 * Key format: page:guest:{sha256(method|host|normalizedPathQuery)}
 */
final class GuestCacheKey
{
    public const string PREFIX = 'page:guest:';

    public static function build(string $method, string $host, string $fullUri): string
    {
        $payload = $method . '|' . strtolower($host) . '|' . self::normalizeUri($fullUri);
        return self::PREFIX . hash('sha256', $payload);
    }

    /**
     * Normalize a request URI for stable cache keying.
     *
     * Lowercases the path; sorts query pairs alphabetically (preserving
     * raw key=value pairs so PHP-array params like ?ids[]=1&ids[]=2 are
     * not collapsed/reordered by parse_str+http_build_query). Returns a
     * synthetic /-invalid sentinel for protocol-relative or NUL-laced
     * inputs so they never collide with a real path.
     */
    public static function normalizeUri(string $uri): string
    {
        if (str_contains($uri, "\0") || str_starts_with($uri, '//')) {
            return '/invalid-uri-' . hash('sha256', $uri);
        }

        $parsed = parse_url($uri);
        if ($parsed === false) {
            return '/malformed-uri-' . hash('sha256', $uri);
        }

        $path = strtolower($parsed['path'] ?? '/');

        $query = '';
        if (isset($parsed['query']) && $parsed['query'] !== '') {
            $pairs = array_filter(
                explode('&', $parsed['query']),
                static fn (string $p): bool => $p !== '',
            );
            sort($pairs);
            $query = '?' . implode('&', $pairs);
        }

        return $path . $query;
    }
}
