<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\QueryWatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Item #50: `QueryWatcher::normalizeQueryPattern()` runs several
 * regexes over the incoming SQL (digit stripping, quoted-literal
 * collapse, IN-clause folding). Without a length cap, a malicious or
 * accidental 10K-value IN clause could push the engine into
 * pathological behaviour.
 *
 * The source already caps input at 8192 chars before running the
 * regex pipeline (`$sql = substr($sql, 0, 8192);`). This test pins
 * that invariant so a future refactor can't silently remove the
 * cap.
 *
 * Regressions asserted:
 *   - method runs in well under a second on an 80K-value IN clause
 *     (proves the cap bounds the work)
 *   - source still contains the `substr($sql, 0, 8192)` cap
 *   - the patterns remain safe character-class shapes (no nested
 *     quantifiers like `(a+)+`)
 */
final class QueryWatcherNormalizeLengthCapTest extends TestCase
{
    #[Test]
    public function hugeInClauseNormalisesQuickly(): void
    {
        $watcher = new QueryWatcher();
        $rm = new ReflectionMethod(QueryWatcher::class, 'normalizeQueryPattern');

        // 80,000 comma-separated ints inside an IN clause — more than
        // enough to show pathological behaviour if the pipeline were
        // unbounded.
        $values = implode(',', range(1, 80000));
        $sql = "SELECT * FROM t WHERE id IN ($values)";

        $start = microtime(true);
        $normalized = $rm->invoke($watcher, $sql);
        $elapsed = microtime(true) - $start;

        $this->assertIsString($normalized);
        $this->assertLessThan(
            1.0,
            $elapsed,
            sprintf('normalizeQueryPattern must budget work — took %.3fs', $elapsed)
        );
    }

    #[Test]
    public function sourceStillAppliesLengthCapBeforeRegexPipeline(): void
    {
        $rm = new ReflectionMethod(QueryWatcher::class, 'normalizeQueryPattern');
        $start = (int) $rm->getStartLine();
        $end = (int) $rm->getEndLine();
        $lines = file((string) $rm->getFileName()) ?: [];
        $body = implode('', array_slice($lines, $start - 1, $end - $start + 1));

        $this->assertMatchesRegularExpression(
            '/substr\s*\(\s*\$sql\s*,\s*0\s*,\s*\d+\s*\)/',
            $body,
            'normalizeQueryPattern must cap input length before regexes run'
        );
    }

    #[Test]
    public function patternsUseSafeCharacterClassesNotNestedQuantifiers(): void
    {
        $rm = new ReflectionMethod(QueryWatcher::class, 'normalizeQueryPattern');
        $start = (int) $rm->getStartLine();
        $end = (int) $rm->getEndLine();
        $lines = file((string) $rm->getFileName()) ?: [];
        $body = implode('', array_slice($lines, $start - 1, $end - $start + 1));

        // Nested quantifiers like (x+)+ or (x*)+ are the catastrophic
        // backtracking signature. None of the normalisation regexes
        // should ever take that shape.
        $this->assertDoesNotMatchRegularExpression(
            '/\([^)]*[+*]\)[+*]/',
            $body,
            'normalizeQueryPattern must not contain nested quantifier regexes'
        );
    }

    #[Test]
    public function digitsAndQuotedLiteralsStillCollapseToPlaceholders(): void
    {
        $watcher = new QueryWatcher();
        $rm = new ReflectionMethod(QueryWatcher::class, 'normalizeQueryPattern');

        $sql = "SELECT * FROM users WHERE id = 42 AND name = 'alice' AND age IN (1, 2, 3)";
        $normalized = $rm->invoke($watcher, $sql);

        $this->assertIsString($normalized);
        $this->assertStringContainsString('id = ?', $normalized);
        $this->assertStringContainsString('name = ?', $normalized);
        $this->assertStringContainsString('IN (?)', $normalized);
    }
}
