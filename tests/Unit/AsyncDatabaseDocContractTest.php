<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Async\AsyncDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Item #33: the class name `AsyncDatabase` strongly implies parallel,
 * non-blocking I/O. It does not — PDO is synchronous, queries execute
 * sequentially, and every call blocks the fiber from resolve to
 * resolve. A developer skimming only the name (not the pre-existing
 * WARNING note) could easily expect amphp/reactphp-style
 * concurrency and ship a performance regression.
 *
 * Renaming the class would break every consumer. Instead, [R38]
 * hardens the docblock so the hazard is impossible to miss on casual
 * reading, and this test pins the hazard wording in place so a
 * future cleanup can't quietly delete it.
 */
final class AsyncDatabaseDocContractTest extends TestCase
{
    private function classDoc(): string
    {
        return (new ReflectionClass(AsyncDatabase::class))->getDocComment() ?: '';
    }

    #[Test]
    public function docCallsOutThatQueriesAreNotParallel(): void
    {
        $doc = $this->classDoc();
        $this->assertMatchesRegularExpression('/sequential/i', $doc);
        $this->assertMatchesRegularExpression('/not.*parallel|not.*concurrent|no.*parallel/i', $doc);
    }

    #[Test]
    public function docCallsOutThatCallsBlockTheFiber(): void
    {
        $doc = $this->classDoc();
        $this->assertMatchesRegularExpression('/block/i', $doc);
        $this->assertMatchesRegularExpression('/fiber/i', $doc);
    }

    #[Test]
    public function docPointsToTrulyAsyncAlternatives(): void
    {
        $doc = $this->classDoc();
        // The warning should keep naming a real replacement so users
        // aren't left guessing. amphp/mysql or reactphp/mysql.
        $this->assertMatchesRegularExpression('/amphp|reactphp/i', $doc);
    }
}
