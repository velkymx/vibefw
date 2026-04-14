<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Fw\Auth\Auth;

final class AuthWeakKeyTest extends TestCase
{
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->envBackup = [
            'APP_ENV' => $_ENV['APP_ENV'] ?? null,
            'APP_KEY' => $_ENV['APP_KEY'] ?? null,
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }

    private function callGetCookieSecret(): string
    {
        $ref = new ReflectionClass(Auth::class);
        $method = $ref->getMethod('getCookieSecret');
        return $method->invoke(null);
    }

    public static function weakKeyProvider(): array
    {
        return [
            'all-same-char'      => [str_repeat('a', 64)],
            'repeated-2-char'    => [str_repeat('ab', 32)],
            'only-letters'       => [str_repeat('abcdefgh', 8)],
            'only-numbers'       => [str_repeat('12345678', 8)],
            'weak-prefix-secret' => ['secret' . str_repeat('x', 58)],
        ];
    }

    #[Test]
    #[DataProvider('weakKeyProvider')]
    public function weakKeyThrowsInProduction(string $key): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_KEY'] = $key;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_KEY.*weak/i');

        $this->callGetCookieSecret();
    }

    #[Test]
    public function weakKeyAllowedInNonProduction(): void
    {
        $_ENV['APP_ENV'] = 'local';
        $_ENV['APP_KEY'] = str_repeat('a', 64);

        $key = $this->callGetCookieSecret();
        $this->assertSame(str_repeat('a', 64), $key);
    }

    #[Test]
    public function strongKeyPassesInProduction(): void
    {
        $strong = bin2hex(random_bytes(32));
        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_KEY'] = $strong;

        $key = $this->callGetCookieSecret();
        $this->assertSame($strong, $key);
    }
}
