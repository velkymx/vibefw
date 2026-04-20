<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponseCspMetaTagTest extends TestCase
{
    protected function tearDown(): void
    {
        Response::setDefaultCsp(null);
    }

    #[Test]
    public function cspMetaTagRendersWithHttpEquivAndDefaultPolicy(): void
    {
        $tag = Response::cspMetaTag();

        $this->assertStringStartsWith('<meta http-equiv="Content-Security-Policy" content="', $tag);
        $this->assertStringContainsString(htmlspecialchars(Response::DEFAULT_CSP, ENT_QUOTES, 'UTF-8'), $tag);
        $this->assertStringEndsWith('">', $tag);
    }

    #[Test]
    public function cspMetaTagRespectsPerCallOverride(): void
    {
        $custom = "default-src 'self' https://cdn.example.com;";
        $tag = Response::cspMetaTag($custom);

        $this->assertStringContainsString('content="' . htmlspecialchars($custom, ENT_QUOTES, 'UTF-8') . '"', $tag);
    }

    #[Test]
    public function cspMetaTagHonoursSetDefaultCsp(): void
    {
        $configured = "default-src 'self' https://assets.example.com;";
        Response::setDefaultCsp($configured);

        $tag = Response::cspMetaTag();

        $this->assertStringContainsString(htmlspecialchars($configured, ENT_QUOTES, 'UTF-8'), $tag);
    }

    #[Test]
    public function cspMetaTagEscapesSpecialCharacters(): void
    {
        $policy = "default-src 'self' \"https://evil\" <script>;";
        $tag = Response::cspMetaTag($policy);

        $this->assertStringNotContainsString('<script>', $tag);
        $this->assertStringContainsString('&lt;script&gt;', $tag);
        $this->assertStringContainsString('&quot;', $tag);
    }
}
