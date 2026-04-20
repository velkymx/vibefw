<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponseCspTest extends TestCase
{
    protected function tearDown(): void
    {
        Response::setDefaultCsp(null);
    }

    #[Test]
    public function defaultCspIsAppliedWhenNoOverride(): void
    {
        $response = new Response()->securityHeaders();

        $this->assertSame(Response::DEFAULT_CSP, $response->getHeaders()['Content-Security-Policy'] ?? null);
    }

    #[Test]
    public function perCallCspOverridesDefault(): void
    {
        $custom = "default-src 'self' https://cdn.example.com;";
        $response = new Response()->securityHeaders($custom);

        $this->assertSame($custom, $response->getHeaders()['Content-Security-Policy'] ?? null);
    }

    #[Test]
    public function setDefaultCspSwitchesGlobalDefault(): void
    {
        $configured = "default-src 'self' https://assets.example.com;";
        Response::setDefaultCsp($configured);

        $response = new Response()->securityHeaders();

        $this->assertSame($configured, $response->getHeaders()['Content-Security-Policy'] ?? null);
    }

    #[Test]
    public function setDefaultCspNullResetsToConst(): void
    {
        Response::setDefaultCsp("dummy");
        Response::setDefaultCsp(null);

        $response = new Response()->securityHeaders();

        $this->assertSame(Response::DEFAULT_CSP, $response->getHeaders()['Content-Security-Policy'] ?? null);
    }

    #[Test]
    public function appConfigExposesCspKey(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        $this->assertArrayHasKey('security', $config);
        $this->assertArrayHasKey('csp', $config['security']);
        $this->assertIsString($config['security']['csp']);
    }
}
