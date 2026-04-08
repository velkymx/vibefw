<?php

declare(strict_types=1);

namespace Fw\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UpgradeGuideTest extends TestCase
{
    private const string GUIDE = __DIR__ . '/../../UPGRADE.md';

    #[Test]
    public function upgradeGuideExists(): void
    {
        $this->assertFileExists(self::GUIDE);
    }

    #[Test]
    public function documentsBreakingChanges(): void
    {
        $content = file_get_contents(self::GUIDE);

        $this->assertStringContainsString('Breaking', $content);
        $this->assertStringContainsString('Controller', $content);
        $this->assertStringContainsString('Result', $content);
        $this->assertStringContainsString('Option', $content);
        $this->assertStringContainsString('FormRequest', $content);
    }

    #[Test]
    public function documentsRemovedMethods(): void
    {
        $content = file_get_contents(self::GUIDE);

        $this->assertStringContainsString('validate()', $content);
        $this->assertStringContainsString('match(', $content);
        $this->assertStringContainsString('$fillable', $content);
    }

    #[Test]
    public function includesFixCommands(): void
    {
        $content = file_get_contents(self::GUIDE);

        $this->assertStringContainsString('php fw fix', $content);
        $this->assertStringContainsString('php fw check', $content);
    }
}
