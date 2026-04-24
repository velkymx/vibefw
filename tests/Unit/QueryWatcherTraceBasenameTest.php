<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\QueryWatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Item #51: `QueryWatcher::getSimplifiedTrace()` emitted the full
 * absolute path of each frame (e.g. `/home/deploy/app/src/...`) into
 * the warning log. That's a low-severity info-disclosure: log files
 * end up documenting the server's directory layout and deploy
 * user's home.
 *
 * [R55] switches to `basename($frame['file'])` for the location
 * component of each frame. Line numbers are preserved — still useful
 * for navigating the repo; just no system-level path data.
 */
final class QueryWatcherTraceBasenameTest extends TestCase
{
    #[Test]
    public function traceOutputContainsOnlyBasenamesNotAbsolutePaths(): void
    {
        $watcher = new QueryWatcher();
        $rm = new ReflectionMethod(QueryWatcher::class, 'getSimplifiedTrace');
        $trace = (string) $rm->invoke($watcher);

        $this->assertNotSame('', $trace);

        // Nothing in the emitted trace should look like an absolute path.
        $this->assertStringNotContainsString(
            '/src/',
            $trace,
            'simplified trace must emit basenames, not directory paths'
        );
        $this->assertStringNotContainsString(
            '/tests/',
            $trace,
            'simplified trace must emit basenames, not directory paths'
        );
        $this->assertDoesNotMatchRegularExpression(
            '#/[A-Za-z0-9_-]+/[A-Za-z0-9_-]+\.php#',
            $trace,
            'simplified trace must not expose nested path segments'
        );
    }

    #[Test]
    public function traceStillIncludesLineNumbers(): void
    {
        $watcher = new QueryWatcher();
        $rm = new ReflectionMethod(QueryWatcher::class, 'getSimplifiedTrace');
        $trace = (string) $rm->invoke($watcher);

        $this->assertMatchesRegularExpression(
            '/\.php:\d+/',
            $trace,
            'line numbers must still be present for debug navigation'
        );
    }

    #[Test]
    public function sourceUsesBasenameInLocation(): void
    {
        $rm = new ReflectionMethod(QueryWatcher::class, 'getSimplifiedTrace');
        $start = (int) $rm->getStartLine();
        $end = (int) $rm->getEndLine();
        $lines = file((string) $rm->getFileName()) ?: [];
        $body = implode('', array_slice($lines, $start - 1, $end - $start + 1));

        $this->assertMatchesRegularExpression(
            '/basename\s*\(\s*\$frame\[\'file\'\]/',
            $body,
            'getSimplifiedTrace must wrap $frame[\'file\'] with basename()'
        );
    }
}
