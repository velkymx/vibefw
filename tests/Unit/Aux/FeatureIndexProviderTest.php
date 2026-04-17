<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class FeatureIndexProviderTest extends TestCase
{
    private const CLASS_NAME = 'App\\Providers\\FeatureIndexProvider';

    public function testProviderClassExists(): void
    {
        $this->assertTrue(
            class_exists(self::CLASS_NAME),
            'app/Providers/FeatureIndexProvider.php must ship so app devs have a starting template',
        );
    }

    public function testProviderExtendsServiceProvider(): void
    {
        $rc = new ReflectionClass(self::CLASS_NAME);

        $this->assertSame(
            \Fw\Core\ServiceProvider::class,
            $rc->getParentClass()->getName(),
        );
    }

    public function testRegisterBindsFeatureIndex(): void
    {
        $rc = new ReflectionClass(self::CLASS_NAME);
        $method = $rc->getMethod('register');

        $file = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($file, $start, $end - $start));

        $this->assertStringContainsString('FeatureIndex', $body);
        $this->assertStringContainsString('singleton', $body);
    }

    public function testProviderIncludesExampleFeature(): void
    {
        $rc = new ReflectionClass(self::CLASS_NAME);

        $source = file_get_contents($rc->getFileName());

        $this->assertStringContainsString('new Feature(', $source, 'Template must show how to add a Feature entry');
    }

    public function testProviderIncludesCustomizeMarker(): void
    {
        $rc = new ReflectionClass(self::CLASS_NAME);

        $source = file_get_contents($rc->getFileName());

        $this->assertStringContainsString('CUSTOMIZE:', $source, 'Stubs must mark customization points per framework convention');
    }
}
