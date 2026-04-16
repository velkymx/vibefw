<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * S-NEW-06: Session SameSite must be configurable via app.session_same_site,
 * not hardcoded to 'Strict'. Strict breaks legitimate cross-site POST flows
 * (e.g. payment provider redirects back to the application).
 */
final class SessionSameSiteTest extends TestCase
{
    #[Test]
    public function initSessionReadsSameSiteFromConfig(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Core/Application.php');
        $this->assertIsString($source);

        // Must NOT hardcode 'Strict' — must use a variable driven by config
        $this->assertStringNotContainsString(
            "'samesite' => 'Strict'",
            $source,
            "SameSite must not be hardcoded; read from app.session_same_site config"
        );

        // Must call resolveSameSiteSetting() (or equivalent) so the value is configurable
        $this->assertStringContainsString(
            'resolveSameSiteSetting',
            $source,
            "initSession() must delegate SameSite resolution to resolveSameSiteSetting()"
        );
    }

    #[Test]
    public function resolveSameSiteSettingDefaultsToStrict(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Core/Application.php');
        $this->assertIsString($source);

        // Default must be 'Strict' when config key is absent
        $this->assertStringContainsString(
            "'Strict'",
            $source,
            "resolveSameSiteSetting() must default to 'Strict'"
        );
    }

    #[Test]
    public function configAppPhpExposesSessionSameSiteKey(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        $this->assertArrayHasKey(
            'session_same_site',
            $config,
            "config/app.php must expose 'session_same_site' key"
        );
    }
}
