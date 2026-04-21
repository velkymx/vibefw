<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Core\Application;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApplicationVersionTest extends TestCase
{
    #[Test]
    public function versionConstantIsDefined(): void
    {
        $this->assertTrue(defined(Application::class . '::VERSION'));
    }

    #[Test]
    public function versionMatchesSemVer(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$/',
            Application::VERSION,
        );
    }

    #[Test]
    public function versionIsMajor3(): void
    {
        $this->assertStringStartsWith('3.', Application::VERSION);
    }

    #[Test]
    public function versionConstantIsTypedString(): void
    {
        $reflection = new \ReflectionClassConstant(Application::class, 'VERSION');

        $type = $reflection->getType();
        $this->assertNotNull($type, 'VERSION must declare a type (PHP 8.3+ typed constant).');
        $this->assertSame('string', (string) $type);
    }
}
