<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Middleware\AuthMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AuthMiddlewareSafeRedirectTest extends TestCase
{
    private function isSafe(string $url): bool
    {
        $rc = new ReflectionClass(AuthMiddleware::class);
        $instance = $rc->newInstanceWithoutConstructor();
        $method = $rc->getMethod('isSafeRedirectUrl');
        return (bool) $method->invoke($instance, $url);
    }

    /** @return array<string, array{0: string}> */
    public static function unsafeEncodedProvider(): array
    {
        return [
            'percent-encoded protocol-relative' => ['/%2F%2Fevil.com'],
            'percent-encoded double-slash prefix' => ['%2F%2Fevil.com'],
            'percent-encoded javascript scheme' => ['javascript%3Aalert(1)'],
            'percent-encoded host path' => ['%2F%2Fevil.com/path'],
        ];
    }

    #[Test]
    #[DataProvider('unsafeEncodedProvider')]
    public function rejectsPercentEncodedOpenRedirects(string $url): void
    {
        $this->assertFalse($this->isSafe($url), "Expected {$url} to be rejected after decoding.");
    }

    #[Test]
    public function acceptsEncodedButSafePath(): void
    {
        $this->assertTrue($this->isSafe('/posts/hello%20world'));
        $this->assertTrue($this->isSafe('/dashboard%2Fsub'));
    }

    #[Test]
    public function existingPlainRulesStillWork(): void
    {
        $this->assertFalse($this->isSafe(''));
        $this->assertFalse($this->isSafe('//evil.com'));
        $this->assertFalse($this->isSafe('javascript:alert(1)'));
        $this->assertTrue($this->isSafe('/dashboard'));
    }
}
