<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Auth\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuthValidateAppKeyTest extends TestCase
{
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->envBackup = [
            'APP_ENV' => $_ENV['APP_ENV'] ?? null,
            'APP_KEY' => $_ENV['APP_KEY'] ?? null,
        ];
        unset($_ENV['APP_KEY']);
        putenv('APP_KEY');
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
                putenv($k);
            } else {
                $_ENV[$k] = $v;
                putenv("{$k}={$v}");
            }
        }
    }

    #[Test]
    public function missingKeyThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_KEY');
        Auth::validateAppKey();
    }

    #[Test]
    public function emptyKeyThrows(): void
    {
        $_ENV['APP_KEY'] = '';
        $this->expectException(RuntimeException::class);
        Auth::validateAppKey();
    }

    #[Test]
    public function tooShortKeyThrows(): void
    {
        $_ENV['APP_KEY'] = str_repeat('x', 31);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/too short/i');
        Auth::validateAppKey();
    }

    public static function weakProductionKeys(): array
    {
        return [
            'all-same'      => [str_repeat('a', 64)],
            'only-letters'  => [str_repeat('abcdefgh', 8)],
            'weak-prefix'   => ['password' . str_repeat('x', 56)],
        ];
    }

    #[Test]
    #[DataProvider('weakProductionKeys')]
    public function weakKeyThrowsInProduction(string $key): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_KEY'] = $key;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/weak/i');
        Auth::validateAppKey();
    }

    #[Test]
    public function weakKeyAllowedOutsideProduction(): void
    {
        $_ENV['APP_ENV'] = 'local';
        $_ENV['APP_KEY'] = str_repeat('a', 64);
        Auth::validateAppKey();
        $this->assertTrue(true);
    }

    #[Test]
    public function strongKeyPassesInProduction(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_KEY'] = bin2hex(random_bytes(32));
        Auth::validateAppKey();
        $this->assertTrue(true);
    }
}
