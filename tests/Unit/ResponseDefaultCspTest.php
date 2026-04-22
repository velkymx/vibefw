<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Response;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Item #12: DEFAULT_CSP shipped with `img-src 'self' data:`, which re-opens
 * a common XSS/exfil vector whenever the default is actually used. The safer
 * default is `img-src 'self'`; apps that need data: URIs for inline images
 * must opt in via Response::setDefaultCsp() or a per-response header.
 */
final class ResponseDefaultCspTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Response::setDefaultCsp(null);
    }

    protected function tearDown(): void
    {
        Response::setDefaultCsp(null);
        parent::tearDown();
    }

    #[Test]
    public function defaultCspDoesNotAllowDataSchemeForImages(): void
    {
        $this->assertStringNotContainsString(
            'data:',
            Response::DEFAULT_CSP,
            'DEFAULT_CSP must not include the data: scheme — it is a common XSS/exfil vector.'
        );
    }

    #[Test]
    public function defaultCspStillLocksImgSrcToSelf(): void
    {
        $this->assertStringContainsString("img-src 'self'", Response::DEFAULT_CSP);
    }

    #[Test]
    public function cspMetaTagUsesStricterDefault(): void
    {
        $meta = Response::cspMetaTag();
        $this->assertStringContainsString("img-src &#039;self&#039;", $meta);
        $this->assertStringNotContainsString('data:', $meta);
    }

    #[Test]
    public function applicationsCanStillOptInToDataScheme(): void
    {
        $opt = "default-src 'self'; img-src 'self' data:;";
        Response::setDefaultCsp($opt);

        $meta = Response::cspMetaTag();
        $this->assertStringContainsString('data:', $meta);
    }
}
