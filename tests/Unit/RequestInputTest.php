<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Request;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RequestInputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'localhost',
        ];
    }

    private function make(
        array $query = [],
        array $post = [],
        array $headers = [],
        ?array $parsedJson = null,
    ): Request {
        return new Request(
            query: $query,
            post: $post,
            server: [],
            files: [],
            headers: $headers,
            rawBody: '',
            parsedJson: $parsedJson,
        );
    }

    #[Test]
    public function inputReturnsDefaultWhenKeyMissingAndNoJsonBody(): void
    {
        $request = $this->make(query: ['present' => 'q']);

        $this->assertSame('fallback', $request->input('absent', 'fallback'));
    }

    #[Test]
    public function inputDoesNotErrorWhenJsonBodyIsNull(): void
    {
        $request = $this->make(query: ['q' => 'v']);

        $this->assertSame('v', $request->input('q'));
        $this->assertNull($request->input('nope'));
    }

    #[Test]
    public function inputPrefersPostOverJsonOverQuery(): void
    {
        $postFirst = $this->make(
            query: ['k' => 'from-query'],
            post: ['k' => 'from-post'],
            parsedJson: ['k' => 'from-json'],
        );
        $this->assertSame('from-post', $postFirst->input('k'));

        $jsonBeatsQuery = $this->make(
            query: ['k' => 'from-query'],
            parsedJson: ['k' => 'from-json'],
        );
        $this->assertSame('from-json', $jsonBeatsQuery->input('k'));

        $queryOnly = $this->make(query: ['k' => 'from-query']);
        $this->assertSame('from-query', $queryOnly->input('k'));
    }

    #[Test]
    public function inputReadsFromJsonWhenOnlyJsonProvided(): void
    {
        $request = $this->make(parsedJson: ['title' => 'Hello']);

        $this->assertSame('Hello', $request->input('title'));
    }

    #[Test]
    public function inputGuardsAgainstNullJsonWithExplicitCoalesce(): void
    {
        $rm = new \ReflectionMethod(Request::class, 'input');
        $source = file($rm->getFileName()) ?: [];
        $body = implode('', array_slice(
            $source,
            $rm->getStartLine() - 1,
            $rm->getEndLine() - $rm->getStartLine() + 1,
        ));

        $this->assertStringNotContainsString(
            '$this->json()[$key]',
            $body,
            'input() must null-coalesce json() before indexing to avoid null-offset access.',
        );
    }
}
