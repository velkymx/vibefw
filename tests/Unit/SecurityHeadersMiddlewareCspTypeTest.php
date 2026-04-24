<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Application;
use Fw\Core\Config;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Item #47: `SecurityHeadersMiddleware::applySecurityHeaders()`
 * checked `if ($csp !== null)` before emitting the CSP header. That
 * admits `false` and other non-string falsy values as a CSP — the
 * header would be set to the string representation of a bool, which
 * is worse than no CSP: it teaches browsers to ignore the field.
 *
 * [R51] narrows the guard to `is_string($csp) && $csp !== ''`, so
 * null, false, empty string, arrays, and ints are all treated as
 * "do not emit". Only a concrete non-empty CSP string is applied.
 *
 * Tests verify:
 *   - null config → no CSP header
 *   - false config → no CSP header
 *   - empty string config → no CSP header
 *   - array config → no CSP header (misconfiguration)
 *   - valid string config → CSP header emitted verbatim
 */
final class SecurityHeadersMiddlewareCspTypeTest extends TestCase
{
    private function runMiddleware(mixed $cspConfig): Response
    {
        $app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $config = new Config(BASE_PATH);
        $config->set('app.csp', $cspConfig);

        $ref = new ReflectionClass($app);
        $ref->getProperty('configRepository')->setValue($app, $config);
        $ref->getProperty('request')->setValue($app, new Request());

        $middleware = new SecurityHeadersMiddleware($app);

        $response = $middleware->handle(
            new Request(),
            fn (Request $r): Response => new Response('ok')
        );

        $this->assertInstanceOf(Response::class, $response);
        return $response;
    }

    private function cspHeader(Response $response): ?string
    {
        $headers = $response->getHeaders();
        return $headers['Content-Security-Policy'] ?? null;
    }

    #[Test]
    public function nullCspConfigEmitsNoCspHeader(): void
    {
        $this->assertNull($this->cspHeader($this->runMiddleware(null)));
    }

    #[Test]
    public function falseCspConfigEmitsNoCspHeader(): void
    {
        $this->assertNull($this->cspHeader($this->runMiddleware(false)));
    }

    #[Test]
    public function emptyStringCspConfigEmitsNoCspHeader(): void
    {
        $this->assertNull($this->cspHeader($this->runMiddleware('')));
    }

    #[Test]
    public function arrayCspConfigEmitsNoCspHeader(): void
    {
        $this->assertNull($this->cspHeader($this->runMiddleware(['default-src' => "'self'"])));
    }

    #[Test]
    public function integerCspConfigEmitsNoCspHeader(): void
    {
        $this->assertNull($this->cspHeader($this->runMiddleware(0)));
    }

    #[Test]
    public function validStringCspConfigIsEmittedVerbatim(): void
    {
        $policy = "default-src 'self'; script-src 'self'";
        $header = $this->cspHeader($this->runMiddleware($policy));
        $this->assertSame($policy, $header);
    }
}
