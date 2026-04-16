<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Async\AsyncHttp;
use Fw\Async\Deferred;
use Fw\Async\HttpResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * M-NEW-03: AsyncHttp::parseResponse() must:
 *   1. Correctly guard count($parts) < 2 (not < 1 which is always false).
 *   2. Decode Transfer-Encoding: chunked bodies.
 */
final class AsyncHttpParseResponseTest extends TestCase
{
    private AsyncHttp $http;
    private ReflectionClass $ref;

    protected function setUp(): void
    {
        $this->http = new AsyncHttp();
        $this->ref  = new ReflectionClass(AsyncHttp::class);
    }

    // -------------------------------------------------------------------------
    // Helper: invoke private parseResponse
    // -------------------------------------------------------------------------

    private function parse(string $buffer): Deferred
    {
        $method = $this->ref->getMethod('parseResponse');

        $deferred = new Deferred();
        $method->invoke($this->http, $buffer, $deferred);
        return $deferred;
    }

    private function resolvedResponse(string $buffer): HttpResponse
    {
        $deferred = $this->parse($buffer);
        $this->assertTrue($deferred->isFulfilled(), 'Deferred must be fulfilled (not rejected)');
        $response = $deferred->getValue();
        $this->assertInstanceOf(HttpResponse::class, $response);
        return $response;
    }

    // -------------------------------------------------------------------------
    // Basic response parsing
    // -------------------------------------------------------------------------

    #[Test]
    public function parsesPlainResponseWithBodySeparator(): void
    {
        $buffer = "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n\r\nHello World";

        $response = $this->resolvedResponse($buffer);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('Hello World', $response->body);
        $this->assertSame('text/plain', $response->header('content-type'));
    }

    #[Test]
    public function parsesResponseWithNoBody(): void
    {
        $buffer = "HTTP/1.1 204 No Content\r\n\r\n";

        $response = $this->resolvedResponse($buffer);

        $this->assertSame(204, $response->statusCode);
        $this->assertSame('', $response->body);
    }

    #[Test]
    public function rejectsBufferWithNoHeaderBodySeparator(): void
    {
        // count($parts) < 2 guard: buffer with no \r\n\r\n must reject
        $buffer = "HTTP/1.1 200 OK\r\nContent-Type: text/plain";

        $deferred = $this->parse($buffer);

        $this->assertTrue($deferred->isRejected(), 'Must reject when \\r\\n\\r\\n separator is missing');
    }

    // -------------------------------------------------------------------------
    // Chunked transfer encoding (M-NEW-03 fix)
    // -------------------------------------------------------------------------

    #[Test]
    public function decodesSimpleChunkedBody(): void
    {
        // "Hello " (6 bytes) + "World" (5 bytes)
        $chunkedBody = "6\r\nHello \r\n5\r\nWorld\r\n0\r\n\r\n";
        $buffer = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n{$chunkedBody}";

        $response = $this->resolvedResponse($buffer);

        $this->assertSame('Hello World', $response->body);
    }

    #[Test]
    public function decodesChunkedBodyWithMultipleChunks(): void
    {
        // Chunk sizes: "Wiki"=4, "pedia "=6, "in\r\n\r\nchunks."=d(13)
        $chunkedBody = "4\r\nWiki\r\n6\r\npedia \r\nd\r\nin\r\n\r\nchunks.\r\n0\r\n\r\n";
        $buffer = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n{$chunkedBody}";

        $response = $this->resolvedResponse($buffer);

        $this->assertSame("Wikipedia in\r\n\r\nchunks.", $response->body);
    }

    #[Test]
    public function decodesEmptyChunkedBody(): void
    {
        // Terminator chunk only — body is empty
        $chunkedBody = "0\r\n\r\n";
        $buffer = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n{$chunkedBody}";

        $response = $this->resolvedResponse($buffer);

        $this->assertSame('', $response->body);
    }

    #[Test]
    public function decodesChunkedBodyCaseInsensitiveHeader(): void
    {
        // Header matching must be case-insensitive
        $chunkedBody = "5\r\nhello\r\n0\r\n\r\n";
        $buffer = "HTTP/1.1 200 OK\r\ntransfer-encoding: chunked\r\n\r\n{$chunkedBody}";

        $response = $this->resolvedResponse($buffer);

        $this->assertSame('hello', $response->body);
    }

    #[Test]
    public function leavesNonChunkedBodyUntouched(): void
    {
        // Content-Length response — body must NOT be decoded as chunks
        $body   = "hello";
        $buffer = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\n{$body}";

        $response = $this->resolvedResponse($buffer);

        $this->assertSame('hello', $response->body);
    }

    // -------------------------------------------------------------------------
    // Guard: count($parts) < 1 was always false — verify it is now count < 2
    // -------------------------------------------------------------------------

    #[Test]
    public function sourceCodeUsesCountLessThanTwo(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Async/AsyncHttp.php');
        $this->assertIsString($source);

        // Old dead guard
        $this->assertStringNotContainsString(
            'count($parts) < 1',
            $source,
            'Dead guard count($parts) < 1 must be replaced with < 2'
        );

        // New correct guard
        $this->assertStringContainsString(
            'count($parts) < 2',
            $source,
            'parseResponse() must guard count($parts) < 2 to detect missing header/body separator'
        );
    }
}
