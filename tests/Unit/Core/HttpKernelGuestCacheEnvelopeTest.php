<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Core\HttpKernel;
use Fw\Core\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Item #6: The guest page cache must store and rehydrate the full
 * Response envelope (status code + headers + body), not just the body.
 *
 * Two contracts are locked here:
 *
 *  1. There is an envelope encode/decode pair on HttpKernel that
 *     round-trips a Response through a JSON string. The decoded
 *     Response must report the same status code, headers, and body
 *     as the original. (Headers/status are the regression — body was
 *     already cached.)
 *
 *  2. The cached Response surfaces an `X-Cache: HIT` header so caches
 *     can be diagnosed in production. The original lookup path
 *     attempted this on an immutable Response and threw the result
 *     away; the rehydration path must keep the header.
 */
final class HttpKernelGuestCacheEnvelopeTest extends TestCase
{
    #[Test]
    public function envelopeRoundTripPreservesStatusHeadersAndBody(): void
    {
        $encode = new ReflectionMethod(HttpKernel::class, 'encodeCacheEnvelope');
        $decode = new ReflectionMethod(HttpKernel::class, 'decodeCacheEnvelope');

        $this->assertTrue(
            $encode->isStatic(),
            'encodeCacheEnvelope() must be static so it can run before the kernel binds a request.',
        );
        $this->assertTrue(
            $decode->isStatic(),
            'decodeCacheEnvelope() must be static so cached entries can be rehydrated without an HttpKernel instance.',
        );

        $original = new Response('payload', 201)
            ->header('Content-Type', 'application/json')
            ->header('X-Custom', 'value');

        $payload = $encode->invoke(null, $original);
        $this->assertIsString($payload);

        $rehydrated = $decode->invoke(null, $payload);
        $this->assertInstanceOf(Response::class, $rehydrated);
        $this->assertSame(201, $rehydrated->getStatusCode());
        $this->assertSame('payload', $rehydrated->getBody());

        $headers = $rehydrated->getHeaders();
        $this->assertSame('application/json', $headers['Content-Type'] ?? null);
        $this->assertSame('value', $headers['X-Custom'] ?? null);
    }

    #[Test]
    public function decodeRejectsCorruptCacheEntry(): void
    {
        $decode = new ReflectionMethod(HttpKernel::class, 'decodeCacheEnvelope');

        $this->assertNull(
            $decode->invoke(null, 'not-json'),
            'Corrupt cache entries must decode to null so the kernel can fall through to a normal pipeline run instead of returning garbage.',
        );

        $this->assertNull(
            $decode->invoke(null, json_encode(['only' => 'body'], JSON_THROW_ON_ERROR)),
            'Envelopes missing status/headers/body keys must be rejected — partial entries are indistinguishable from corruption.',
        );
    }

    #[Test]
    public function tryGetFromCacheRehydratesEnvelopeWithHitHeader(): void
    {
        $method = new ReflectionMethod(HttpKernel::class, 'tryGetFromCache');
        $file   = file($method->getFileName());
        $start  = $method->getStartLine() - 1;
        $end    = $method->getEndLine();
        $body   = implode('', array_slice($file, $start, $end - $start));

        $this->assertStringContainsString(
            'decodeCacheEnvelope(',
            $body,
            'tryGetFromCache() must decode through the envelope helper so headers/status survive.',
        );
        $this->assertStringContainsString(
            "header('X-Cache', 'HIT')",
            $body,
            'tryGetFromCache() must keep the X-Cache: HIT header on the rehydrated Response.',
        );

        // Reject the legacy "drop the clone" pattern that started this bug:
        // a header() call whose return value is ignored.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*\$response->header\([^;]*\);/m',
            $body,
            'tryGetFromCache() must not call $response->header() without capturing the clone — that was the original immutability bug.',
        );
    }

    #[Test]
    public function cacheGuestResponseStoresFullEnvelope(): void
    {
        $method = new ReflectionMethod(HttpKernel::class, 'cacheGuestResponse');
        $params = $method->getParameters();

        $this->assertSame(
            'response',
            $params[1]->getName() ?? null,
            'cacheGuestResponse() must accept the full Response object so it can serialize status/headers/body.',
        );
        $this->assertSame(
            Response::class,
            $params[1]->getType()?->getName(),
            'cacheGuestResponse()\'s second parameter must be typed Response, not string.',
        );
    }
}
