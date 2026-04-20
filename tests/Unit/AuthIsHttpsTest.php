<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Auth\Auth;
use Fw\Core\Request;
use Fw\Core\RequestContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AuthIsHttpsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::clear();
        $_SERVER = [];
        Request::setTrustedProxies([]);
    }

    protected function tearDown(): void
    {
        RequestContext::clear();
        $_SERVER = [];
        Request::setTrustedProxies([]);
    }

    private function isHttps(): bool
    {
        $method = new ReflectionMethod(Auth::class, 'isHttps');
        return (bool) $method->invoke(null);
    }

    #[Test]
    public function returnsFalseWithoutRequestContextEvenIfHttpsServerVarSet(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SERVER_PORT'] = 443;

        $this->assertFalse($this->isHttps());
    }

    #[Test]
    public function returnsTrueWhenContextRequestIsSecure(): void
    {
        $request = new Request(
            query: [],
            post: [],
            server: ['HTTPS' => 'on', 'SERVER_PORT' => 443, 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'],
            files: [],
            headers: [],
        );
        RequestContext::create($request);

        $this->assertTrue($this->isHttps());
    }

    #[Test]
    public function returnsFalseWhenContextRequestIsInsecure(): void
    {
        $request = new Request(
            query: [],
            post: [],
            server: ['SERVER_PORT' => 80, 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'],
            files: [],
            headers: [],
        );
        RequestContext::create($request);

        $this->assertFalse($this->isHttps());
    }

    #[Test]
    public function ignoresSpoofedForwardedProtoWithoutTrustedProxy(): void
    {
        $request = new Request(
            query: [],
            post: [],
            server: [
                'SERVER_PORT' => 80,
                'REMOTE_ADDR' => '203.0.113.1',
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/',
            ],
            files: [],
            headers: ['X-Forwarded-Proto' => 'https'],
        );
        RequestContext::create($request);

        $this->assertFalse($this->isHttps());
    }
}
