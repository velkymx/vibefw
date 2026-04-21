<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GitignoreWorkingDocsTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = \dirname(__DIR__, 2);

        if (!is_dir($this->repoRoot . '/.git')) {
            $this->markTestSkipped('Not running inside a git working tree.');
        }
    }

    private function assertIsIgnored(string $pathRelativeToRepo): void
    {
        $cmd = sprintf(
            'git -C %s check-ignore --quiet -- %s',
            escapeshellarg($this->repoRoot),
            escapeshellarg($pathRelativeToRepo),
        );

        exec($cmd, $_, $exit);

        $this->assertSame(
            0,
            $exit,
            "Path '{$pathRelativeToRepo}' must be matched by .gitignore (git check-ignore exit=0).",
        );
    }

    #[Test]
    public function todoMdIsIgnored(): void
    {
        $this->assertIsIgnored('todo.md');
    }

    #[Test]
    public function docsSuperpowersDirIsIgnored(): void
    {
        $this->assertIsIgnored('docs/superpowers/anything.md');
    }
}
