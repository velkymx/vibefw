<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Application;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReleaseTagIntegrityTest extends TestCase
{
    private string $repoRoot;
    private string $tag;

    protected function setUp(): void
    {
        $this->repoRoot = \dirname(__DIR__, 2);
        $this->tag = 'v' . Application::VERSION;

        if (!is_dir($this->repoRoot . '/.git')) {
            $this->markTestSkipped('Not running inside a git working tree.');
        }

        $check = sprintf(
            'git -C %s rev-parse --verify --quiet refs/tags/%s > /dev/null 2>&1',
            escapeshellarg($this->repoRoot),
            escapeshellarg($this->tag),
        );
        exec($check, $_, $exit);

        if ($exit !== 0) {
            $this->markTestSkipped("Tag {$this->tag} does not exist locally.");
        }
    }

    /** @return list<string> */
    private function subjectsReachableFromTag(): array
    {
        $cmd = sprintf(
            'git -C %s log --format=%%s %s',
            escapeshellarg($this->repoRoot),
            escapeshellarg($this->tag),
        );
        exec($cmd, $output);

        return $output;
    }

    #[Test]
    public function tagContainsAllReleaseBlockerFixes(): void
    {
        $subjects = $this->subjectsReachableFromTag();

        foreach (['[R1]', '[R2]', '[R3]', '[R4]', '[R5]'] as $marker) {
            $found = array_any(
                $subjects,
                static fn(string $s): bool => str_contains($s, $marker),
            );

            $this->assertTrue(
                $found,
                "Tag {$this->tag} must include the {$marker} release-blocker fix. "
                . 'Retag v' . Application::VERSION . ' at the current HEAD of 3.0-main.',
            );
        }
    }
}
