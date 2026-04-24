<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApplicationConfiguredProvidersTest extends TestCase
{
    #[Test]
    public function applicationLoadsConfiguredProvidersBeforeBootingRegistry(): void
    {
        $source = file_get_contents(BASE_PATH . '/src/Core/Application.php');
        $this->assertIsString($source);

        $corePos = strpos($source, '$this->registerCoreProviders();');
        $configPos = strpos($source, "\$this->providers->loadFrom(BASE_PATH . '/config/providers.php');");
        $bootPos = strpos($source, '$this->providers->boot();');

        $this->assertNotFalse($corePos, 'Expected core provider registration in Application bootstrap.');
        $this->assertNotFalse(
            $configPos,
            'Application must load config/providers.php so application providers are not dead code.',
        );
        $this->assertNotFalse($bootPos, 'Expected provider boot in Application bootstrap.');
        $this->assertLessThan(
            $configPos,
            $corePos,
            'Configured providers must load after core providers so framework defaults register first.',
        );
        $this->assertLessThan(
            $bootPos,
            $configPos,
            'Configured providers must load before ProviderRegistry::boot() so their register()/boot() lifecycle runs.',
        );
    }

    #[Test]
    public function shippedProvidersConfigOnlyReferencesExistingProviderClasses(): void
    {
        $providers = require BASE_PATH . '/config/providers.php';

        $this->assertIsArray($providers);

        foreach ($providers as $providerClass) {
            $this->assertIsString($providerClass);
            $this->assertTrue(
                class_exists($providerClass),
                "config/providers.php references missing provider class {$providerClass}",
            );
        }
    }
}
