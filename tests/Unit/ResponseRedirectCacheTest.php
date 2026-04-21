<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class ResponseRedirectCacheTest extends TestCase
{
    private function headers(Response $response): array
    {
        $prop = new ReflectionProperty(Response::class, 'headers');
        return $prop->getValue($response);
    }

    public static function permanentCodes(): array
    {
        return [
            '301 Moved Permanently' => [301],
            '308 Permanent Redirect' => [308],
        ];
    }

    public static function temporaryCodes(): array
    {
        return [
            '302 Found' => [302],
            '303 See Other' => [303],
            '307 Temporary' => [307],
        ];
    }

    #[Test]
    #[DataProvider('permanentCodes')]
    public function permanentRedirectsAreCacheable(int $code): void
    {
        $headers = $this->headers((new Response())->redirect('/target', $code));

        $this->assertSame('/target', $headers['Location']);
        $this->assertArrayHasKey('Cache-Control', $headers);
        $this->assertStringContainsString('public', $headers['Cache-Control']);
        $this->assertStringContainsString('max-age=31536000', $headers['Cache-Control']);
        $this->assertArrayHasKey('Expires', $headers);
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z]{3}, \d{2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/',
            $headers['Expires'],
        );
    }

    #[Test]
    #[DataProvider('temporaryCodes')]
    public function temporaryRedirectsOptOutOfCaching(int $code): void
    {
        $headers = $this->headers((new Response())->redirect('/target', $code));

        $this->assertSame('/target', $headers['Location']);
        $this->assertArrayHasKey('Cache-Control', $headers);
        $this->assertStringContainsString('no-store', $headers['Cache-Control']);
        $this->assertArrayNotHasKey('Expires', $headers);
    }

    #[Test]
    public function defaultRedirectIs302AndNotCached(): void
    {
        $response = (new Response())->redirect('/target');
        $headers = $this->headers($response);

        $this->assertStringContainsString('no-store', $headers['Cache-Control']);
    }

    #[Test]
    public function cacheHeaderCanStillBeOverriddenAfterRedirect(): void
    {
        $response = (new Response())
            ->redirect('/target', 301)
            ->header('Cache-Control', 'no-store');

        $this->assertSame('no-store', $this->headers($response)['Cache-Control']);
    }
}
