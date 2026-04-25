<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Cache;

use Fw\Cache\GuestCacheKey;
use Fw\Core\HttpKernel;
use Fw\Middleware\GuestPageCacheMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Item #7: One shared cache-key builder for the guest page cache.
 *
 * Two regressions were possible before this lock-in:
 *   1. The kernel's read path and the middleware's write path used
 *      different hash algorithms (sha256 vs md5) and different URL
 *      shapes (normalized path+query vs raw uri), so writes never
 *      surfaced as reads.
 *   2. Neither path included the request host, so two vhosts on the
 *      same path could serve each other's cached bodies.
 *
 * Locking the contract: a single GuestCacheKey::build() helper exists,
 * both call sites use it, and the produced key is sensitive to method,
 * host, and full path+query.
 */
final class GuestCacheKeyTest extends TestCase
{
    #[Test]
    public function sameInputsProduceSameKey(): void
    {
        $a = GuestCacheKey::build('GET', 'a.example.com', '/posts?page=2');
        $b = GuestCacheKey::build('GET', 'a.example.com', '/posts?page=2');
        $this->assertSame($a, $b);
        $this->assertStringStartsWith('page:guest:', $a);
    }

    #[Test]
    public function differentHostsProduceDifferentKeys(): void
    {
        $a = GuestCacheKey::build('GET', 'a.example.com', '/posts');
        $b = GuestCacheKey::build('GET', 'b.example.com', '/posts');
        $this->assertNotSame(
            $a,
            $b,
            'Cache key must include host so a.example.com and b.example.com cannot serve each other\'s pages.',
        );
    }

    #[Test]
    public function differentMethodsProduceDifferentKeys(): void
    {
        $a = GuestCacheKey::build('GET', 'a.example.com', '/posts');
        $b = GuestCacheKey::build('HEAD', 'a.example.com', '/posts');
        $this->assertNotSame($a, $b, 'Method must be part of the cache key.');
    }

    #[Test]
    public function querystringDifferencesAreCaptured(): void
    {
        $a = GuestCacheKey::build('GET', 'a.example.com', '/posts?page=1');
        $b = GuestCacheKey::build('GET', 'a.example.com', '/posts?page=2');
        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function hostCasingIsNormalized(): void
    {
        $a = GuestCacheKey::build('GET', 'A.example.com', '/posts');
        $b = GuestCacheKey::build('GET', 'a.example.com', '/posts');
        $this->assertSame($a, $b, 'Host comparison must be case-insensitive.');
    }

    #[Test]
    public function httpKernelUsesSharedKeyBuilder(): void
    {
        $method = new ReflectionMethod(HttpKernel::class, 'tryGetFromCache');
        $file   = file($method->getFileName());
        $body   = implode('', array_slice($file, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $this->assertStringContainsString(
            'GuestCacheKey::build(',
            $body,
            'HttpKernel::tryGetFromCache() must build the key via the shared GuestCacheKey helper.',
        );
    }

    #[Test]
    public function middlewareUsesSharedKeyBuilder(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(GuestPageCacheMiddleware::class))->getFileName());

        $this->assertStringContainsString(
            'GuestCacheKey::build(',
            $source,
            'GuestPageCacheMiddleware must build the key via the shared GuestCacheKey helper so reads and writes line up.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/md5\s*\(/',
            $source,
            'GuestPageCacheMiddleware must not key with md5(); the shared builder uses sha256.',
        );
    }
}
