<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\Http\AgentMiddleware;
use Fw\Core\Request;
use Fw\Core\Response;
use PHPUnit\Framework\TestCase;

final class AgentMiddlewareTest extends TestCase
{
    // ── B5: AgentMiddleware must exist and enforce Accept: application/json ───

    public function testClassExists(): void
    {
        $this->assertTrue(
            class_exists(AgentMiddleware::class),
            'AgentMiddleware class must exist at Fw\Aux\Http\AgentMiddleware',
        );
    }

    public function testRejectsRequestWithoutJsonAcceptHeader(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $request = new Request();

        $middleware = new AgentMiddleware();
        $response = $middleware->handle($request, fn($r) => new Response('OK'));

        $this->assertSame(406, $response->getStatusCode());
        unset($_SERVER['HTTP_ACCEPT']);
    }

    public function testAllowsRequestWithJsonAcceptHeader(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $request = new Request();

        $middleware = new AgentMiddleware();
        $response = $middleware->handle($request, fn($r) => new Response('OK'));

        $this->assertSame(200, $response->getStatusCode());
        unset($_SERVER['HTTP_ACCEPT']);
    }

    public function testAllowsRequestWithWildcardAcceptHeader(): void
    {
        $_SERVER['HTTP_ACCEPT'] = '*/*';
        $request = new Request();

        $middleware = new AgentMiddleware();
        $response = $middleware->handle($request, fn($r) => new Response('OK'));

        $this->assertSame(200, $response->getStatusCode());
        unset($_SERVER['HTTP_ACCEPT']);
    }

    public function testSetsAuxVersionHeader(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $request = new Request();

        $middleware = new AgentMiddleware();
        $response = $middleware->handle($request, fn($r) => new Response('OK'));

        $headers = $response->getHeaders();
        $this->assertArrayHasKey('X-AUX-Version', $headers);
        unset($_SERVER['HTTP_ACCEPT']);
    }
}
