<?php

declare(strict_types=1);

namespace Fw\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClaudeMdTest extends TestCase
{
    private const string CLAUDE_MD = __DIR__ . '/../../CLAUDE.md';

    #[Test]
    public function claudeMdExists(): void
    {
        $this->assertFileExists(self::CLAUDE_MD);
    }

    #[Test]
    public function noV2References(): void
    {
        $content = file_get_contents(self::CLAUDE_MD);

        $this->assertStringNotContainsString('$guarded', $content, 'CLAUDE.md must not reference $guarded');
        $this->assertStringNotContainsString('unwrap()', $content, 'CLAUDE.md must not reference unwrap()');
        $this->assertStringNotContainsString('getValue()', $content, 'CLAUDE.md must not reference getValue()');
        $this->assertStringNotContainsString('getError()', $content, 'CLAUDE.md must not reference getError()');
        $this->assertStringNotContainsString("'required|", $content, 'CLAUDE.md must not reference string pipe rules');
        $this->assertStringNotContainsString('enableStrictMode', $content, 'CLAUDE.md must not reference enableStrictMode');
        $this->assertStringNotContainsString('resetStrictMode', $content, 'CLAUDE.md must not reference resetStrictMode');
    }

    #[Test]
    public function documentsV3Commands(): void
    {
        $content = file_get_contents(self::CLAUDE_MD);

        $this->assertStringContainsString('model:inspect', $content);
        $this->assertStringContainsString('route:for', $content);
        $this->assertStringContainsString('db:status', $content);
        $this->assertStringContainsString('test:for', $content);
        $this->assertStringContainsString('error:explain', $content);
    }

    #[Test]
    public function documentsTypedRules(): void
    {
        $content = file_get_contents(self::CLAUDE_MD);

        $this->assertStringContainsString('FormRequest', $content);
        $this->assertStringContainsString('new Required', $content);
    }

    #[Test]
    public function documentsMatchPattern(): void
    {
        $content = file_get_contents(self::CLAUDE_MD);

        $this->assertStringContainsString('->match(', $content);
    }
}
