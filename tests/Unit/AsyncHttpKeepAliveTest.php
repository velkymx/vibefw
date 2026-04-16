<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Async\AsyncHttp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * M-01: AsyncHttp must support keep-alive via Content-Length / chunked
 * response-completion detection, and expose withKeepAlive() for callers.
 */
final class AsyncHttpKeepAliveTest extends TestCase
{
    private AsyncHttp $http;
    private ReflectionClass $ref;

    protected function setUp(): void
    {
        $this->http = new AsyncHttp();
        $this->ref  = new ReflectionClass(AsyncHttp::class);
    }

    // -------------------------------------------------------------------------
    // withKeepAlive() — fluent API
    // -------------------------------------------------------------------------

    #[Test]
    public function withKeepAliveReturnsCloneNotSameInstance(): void
    {
        $clone = $this->http->withKeepAlive();

        $this->assertNotSame($this->http, $clone);
    }

    #[Test]
    public function withKeepAliveChangesConnectionHeader(): void
    {
        $clone = $this->http->withKeepAlive();

        $defaultHeaders = $this->ref->getProperty('defaultHeaders')->getValue($clone);
        $this->assertSame('keep-alive', $defaultHeaders['Connection']);
    }

    #[Test]
    public function originalIsUnchangedAfterWithKeepAlive(): void
    {
        $this->http->withKeepAlive();

        $defaultHeaders = $this->ref->getProperty('defaultHeaders')->getValue($this->http);
        $this->assertSame('close', $defaultHeaders['Connection']);
    }

    // -------------------------------------------------------------------------
    // isResponseComplete() — header parsing
    // -------------------------------------------------------------------------

    private function isComplete(string $buffer): bool
    {
        $method = $this->ref->getMethod('isResponseComplete');
        return $method->invoke($this->http, $buffer);
    }

    #[Test]
    public function incompleteHeadersReturnFalse(): void
    {
        // No \r\n\r\n separator — headers not fully received yet
        $this->assertFalse($this->isComplete("HTTP/1.1 200 OK\r\nContent-Length: 5\r\n"));
    }

    #[Test]
    public function contentLengthResponseCompleteWhenBodyFull(): void
    {
        $buffer = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello";
        $this->assertTrue($this->isComplete($buffer));
    }

    #[Test]
    public function contentLengthResponseIncompleteWhenBodyPartial(): void
    {
        $buffer = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhel"; // only 3 of 5 bytes
        $this->assertFalse($this->isComplete($buffer));
    }

    #[Test]
    public function chunkedResponseCompleteWhenTerminatorPresent(): void
    {
        $chunked = "5\r\nhello\r\n0\r\n\r\n";
        $buffer  = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n{$chunked}";
        $this->assertTrue($this->isComplete($buffer));
    }

    #[Test]
    public function chunkedResponseIncompleteWithoutTerminator(): void
    {
        $partial = "5\r\nhello\r\n"; // no 0\r\n\r\n terminator
        $buffer  = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n{$partial}";
        $this->assertFalse($this->isComplete($buffer));
    }

    #[Test]
    public function noContentLengthOrChunkedReturnsFalse(): void
    {
        // Server will use Connection: close; we wait for EOF instead
        $buffer = "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n\r\nhello";
        $this->assertFalse($this->isComplete($buffer));
    }

    #[Test]
    public function contentLengthZeroIsComplete(): void
    {
        $buffer = "HTTP/1.1 204 No Content\r\nContent-Length: 0\r\n\r\n";
        $this->assertTrue($this->isComplete($buffer));
    }
}
