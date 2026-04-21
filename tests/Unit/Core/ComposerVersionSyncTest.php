<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Core\Application;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComposerVersionSyncTest extends TestCase
{
    /** @return array<string, mixed> */
    private function readComposer(): array
    {
        $path = \dirname(__DIR__, 3) . '/composer.json';
        $raw = file_get_contents($path);
        $this->assertIsString($raw, "composer.json must be readable at {$path}");

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, 'composer.json must decode to an object.');

        return $decoded;
    }

    #[Test]
    public function composerDeclaresVersion(): void
    {
        $this->assertArrayHasKey(
            'version',
            $this->readComposer(),
            'composer.json must declare a "version" field so Application::VERSION and the package manifest stay in sync.',
        );
    }

    #[Test]
    public function composerVersionMatchesApplicationVersion(): void
    {
        $this->assertSame(
            Application::VERSION,
            $this->readComposer()['version'] ?? null,
            'composer.json "version" must match Fw\\Core\\Application::VERSION.',
        );
    }
}
