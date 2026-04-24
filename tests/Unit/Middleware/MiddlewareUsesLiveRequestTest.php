<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Middleware;

use Fw\Core\Application;
use Fw\Core\Config;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Middleware\ApiAuthMiddleware;
use Fw\Middleware\SecurityHeadersMiddleware;
use Fw\Middleware\SpaAuthMiddleware;
use Fw\Middleware\TokenAbilityMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Item #1 (companion): middleware that emits error responses or makes
 * security decisions must read from the Request argument passed to
 * handle(), not from $this->app->request. Reading $app->request is unsafe
 * because the kernel may not have rebound the boot-time placeholder, and
 * even when it does, threading the per-request object through handle()
 * is the only worker-mode-safe contract.
 */
final class MiddlewareUsesLiveRequestTest extends TestCase
{
    private function applicationWithBootRequest(string $bootUri = '/__boot__', bool $bootSecure = false): Application
    {
        $app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();

        $config = new Config(BASE_PATH);

        $bootServer = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $bootUri];
        if ($bootSecure) {
            $bootServer['HTTPS'] = 'on';
        }
        $bootRequest = new Request(server: $bootServer, uri: $bootUri);

        $ref = new ReflectionClass($app);
        $ref->getProperty('configRepository')->setValue($app, $config);
        $ref->getProperty('request')->setValue($app, $bootRequest);
        $ref->getProperty('response')->setValue($app, new Response());

        return $app;
    }

    private function liveRequest(string $uri, string $method = 'GET', bool $secure = false, array $headers = []): Request
    {
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri];
        if ($secure) {
            $server['HTTPS'] = 'on';
        }
        return new Request(
            server: $server,
            headers: $headers,
            method: $method,
            uri: $uri,
        );
    }

    private function decodeJson(Response $response): array
    {
        $body = $response->getBody();
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded, 'Expected JSON-decodable error body, got: ' . $body);
        return $decoded;
    }

    #[Test]
    public function apiAuthUnauthorizedUsesLiveRequestUri(): void
    {
        $app = $this->applicationWithBootRequest('/__boot__');
        $middleware = new ApiAuthMiddleware($app);

        $live = $this->liveRequest('/api/v1/posts');
        $response = $middleware->handle($live, fn () => new Response('ok'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->getStatusCode());

        $decoded = $this->decodeJson($response);
        $this->assertSame(
            '/api/v1/posts',
            $decoded['instance'] ?? null,
            'ApiAuthMiddleware must build the unauthorized error instance from the '
            . 'live $request, not from $app->request (boot-time placeholder).',
        );
    }

    #[Test]
    public function tokenAbilityForbiddenUsesLiveRequestUri(): void
    {
        $app = $this->applicationWithBootRequest('/__boot__');
        $middleware = new TokenAbilityMiddleware($app, 'posts:write');

        $live = $this->liveRequest('/api/v1/admin/posts', 'POST');
        $response = $middleware->handle($live, fn () => new Response('ok'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());

        $decoded = $this->decodeJson($response);
        $this->assertSame(
            '/api/v1/admin/posts',
            $decoded['instance'] ?? null,
            'TokenAbilityMiddleware must build the forbidden error instance from the '
            . 'live $request, not from $app->request.',
        );
    }

    #[Test]
    public function spaAuthForbiddenUsesLiveRequestUri(): void
    {
        // SpaAuthMiddleware loads config/api.php in its constructor; with no
        // spa_domains and APP_ENV=production the validateOrigin() short-circuits
        // to a forbidden response before any session/CSRF check runs.
        $previousEnv = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = 'production';

        try {
            $app = $this->applicationWithBootRequest('/__boot__');
            $middleware = new SpaAuthMiddleware($app);

            $live = $this->liveRequest(
                '/spa/api/secrets',
                'POST',
                false,
                ['origin' => 'https://attacker.example']
            );
            $response = $middleware->handle($live, fn () => new Response('ok'));

            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame(403, $response->getStatusCode());

            $decoded = $this->decodeJson($response);
            $this->assertSame(
                '/spa/api/secrets',
                $decoded['instance'] ?? null,
                'SpaAuthMiddleware must build the forbidden error instance from the '
                . 'live $request, not from $app->request.',
            );
        } finally {
            if ($previousEnv === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $previousEnv;
            }
        }
    }

    #[Test]
    public function securityHeadersHstsKeysOnLiveRequestSecureFlag(): void
    {
        $app = $this->applicationWithBootRequest('/__boot__', bootSecure: false);
        $middleware = new SecurityHeadersMiddleware($app);

        $live = $this->liveRequest('/secure/page', 'GET', secure: true);
        $response = $middleware->handle($live, fn () => new Response('ok'));

        $this->assertInstanceOf(Response::class, $response);
        $headers = $response->getHeaders();
        $this->assertArrayHasKey(
            'Strict-Transport-Security',
            $headers,
            'SecurityHeadersMiddleware must read isSecure() from the live $request '
            . '(HTTPS) — reading $app->request (boot HTTP) would suppress HSTS.',
        );
    }

    #[Test]
    public function securityHeadersHstsAbsentWhenLiveRequestPlain(): void
    {
        $app = $this->applicationWithBootRequest('/__boot__', bootSecure: true);
        $middleware = new SecurityHeadersMiddleware($app);

        $live = $this->liveRequest('/plain/page', 'GET', secure: false);
        $response = $middleware->handle($live, fn () => new Response('ok'));

        $this->assertInstanceOf(Response::class, $response);
        $headers = $response->getHeaders();
        $this->assertArrayNotHasKey(
            'Strict-Transport-Security',
            $headers,
            'SecurityHeadersMiddleware must NOT emit HSTS for plain-HTTP live '
            . 'requests, even if $app->request was bound to a secure boot request.',
        );
    }
}
