<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Cache\GuestCacheKey;
use Fw\Core\HttpKernel;
use Fw\Core\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Item H3: The guest page cache must store and rehydrate the full
 * Response envelope (status code + headers + body), not just the body.
 *
 * The envelope encode/decode pair lives on GuestCacheKey so both
 * HttpKernel and GuestPageCacheMiddleware share the same format.
 */
final class HttpKernelGuestCacheEnvelopeTest extends TestCase
{
    #[Test]
    public function envelopeRoundTripPreservesStatusHeadersAndBody(): void
    {
        $original = new Response('payload', 201)
            ->header('Content-Type', 'application/json')
            ->header('X-Custom', 'value');

        $payload = GuestCacheKey::encodeEnvelope($original);
        $this->assertIsString($payload);

        $rehydrated = GuestCacheKey::decodeEnvelope($payload);
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
        $this->assertNull(
            GuestCacheKey::decodeEnvelope('not-json'),
            'Corrupt cache entries must decode to null so callers fall through instead of serving garbage.',
        );

        $this->assertNull(
            GuestCacheKey::decodeEnvelope(json_encode(['only' => 'body'], JSON_THROW_ON_ERROR)),
            'Envelopes missing status/headers/body keys must be rejected.',
        );
    }

    #[Test]
    public function tryGetFromCacheUsesSharedEnvelopeDecoder(): void
    {
        $method = new ReflectionMethod(HttpKernel::class, 'tryGetFromCache');
        $source = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($source, $start, $end - $start));

        $this->assertStringContainsString(
            'GuestCacheKey::decodeEnvelope(',
            $body,
            'tryGetFromCache() must decode through GuestCacheKey::decodeEnvelope() so headers/status survive.',
        );
        $this->assertStringContainsString(
            "header('X-Cache', 'HIT')",
            $body,
            'tryGetFromCache() must keep the X-Cache: HIT header on the rehydrated Response.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*\$response->header\([^;]*\);/m',
            $body,
            'tryGetFromCache() must not call $response->header() without capturing the clone.',
        );
    }

    #[Test]
    public function cacheGuestResponseUsesSharedEnvelopeEncoder(): void
    {
        $method = new ReflectionMethod(HttpKernel::class, 'cacheGuestResponse');
        $source = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($source, $start, $end - $start));

        $this->assertStringContainsString(
            'GuestCacheKey::encodeEnvelope(',
            $body,
            'cacheGuestResponse() must encode through GuestCacheKey::encodeEnvelope().',
        );

        $params = $method->getParameters();
        $this->assertSame(
            'response',
            $params[1]->getName() ?? null,
            'cacheGuestResponse() must accept the full Response object.',
        );
        $this->assertSame(
            Response::class,
            $params[1]->getType()?->getName(),
            'cacheGuestResponse() second parameter must be typed Response.',
        );
    }

    #[Test]
    public function guestPageCacheMiddlewareWritesEnvelopeNotBodyOnly(): void
    {
        $method = new ReflectionMethod(\Fw\Middleware\GuestPageCacheMiddleware::class, 'handle');
        $source = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($source, $start, $end - $start));

        $this->assertStringContainsString(
            'GuestCacheKey::encodeEnvelope(',
            $body,
            'GuestPageCacheMiddleware must write the full envelope, not body-only.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/->getBody\(\)/',
            $body,
            'GuestPageCacheMiddleware must not cache ->getBody() alone.',
        );
    }
}
