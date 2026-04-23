<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Database\QueryBuilder;
use Fw\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Item #18: QueryBuilder::compileSelectColumn() emits any column that
 * contains `(` or a space verbatim, on the assumption that select()
 * already validated it. That's true for the public API, but defence in
 * depth matters here — if a future callsite ever bypasses select()
 * (e.g. a trait, a subclass, or internal state manipulation), the raw
 * passthrough is an SQL-injection foothold.
 *
 * Lock in: compileSelectColumn's raw branch validates the expression
 * against an aggregate whitelist (COUNT/SUM/AVG/MAX/MIN/COALESCE/IFNULL/
 * NULLIF with a safe inner argument and an optional plain alias).
 * Anything else — BENCHMARK, SLEEP, UNION, statement chaining — throws.
 */
final class QueryBuilderSelectColumnWhitelistTest extends TestCase
{
    protected ?Connection $db = null;

    protected function setUp(): void
    {
        parent::setUp();
        Connection::reset();

        $this->db = Connection::getInstance([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->db->query('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, tag VARCHAR(50), n INTEGER)');
        $this->db->insert('items', ['tag' => 'alpha', 'n' => 10]);
        $this->db->insert('items', ['tag' => 'beta',  'n' => 20]);
    }

    /** Drive compileSelectColumn() directly so we can assert the whitelist regardless of how it was reached. */
    private function compile(string $column): string
    {
        $qb = $this->db->table('items');
        $method = (new ReflectionClass(QueryBuilder::class))->getMethod('compileSelectColumn');
        return $method->invoke($qb, $column);
    }

    #[Test]
    public function passthroughAcceptsFrameworkAggregateShapes(): void
    {
        // These mirror exactly what aggregateExpr() emits post-quoteIdentifier.
        $this->assertSame('COUNT(*) as aggregate',            $this->compile('COUNT(*) as aggregate'));
        $this->assertSame('COUNT(`tag`) as aggregate',        $this->compile('COUNT(`tag`) as aggregate'));
        $this->assertSame('COUNT(DISTINCT `tag`) as aggregate', $this->compile('COUNT(DISTINCT `tag`) as aggregate'));
        $this->assertSame('SUM(`n`) as aggregate',            $this->compile('SUM(`n`) as aggregate'));
        $this->assertSame('AVG("n") as aggregate',            $this->compile('AVG("n") as aggregate'));
        $this->assertSame('MAX(`items`.`n`) as aggregate',    $this->compile('MAX(`items`.`n`) as aggregate'));
        $this->assertSame('MIN("items"."n") as aggregate',    $this->compile('MIN("items"."n") as aggregate'));
    }

    #[Test]
    public function passthroughAcceptsUserSelectAggregateWithoutQuotes(): void
    {
        // Prior behaviour preserved: select('COUNT(*) as aggregate') without pre-quoting still compiles.
        $this->assertSame('COUNT(*) as aggregate', $this->compile('COUNT(*) as aggregate'));
        $this->assertSame('COUNT(tag) as total',   $this->compile('COUNT(tag) as total'));
    }

    #[Test]
    public function passthroughRejectsUnknownFunction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unsafe|whitelist|invalid/i');
        $this->compile("BENCHMARK(1000000,MD5('x'))");
    }

    #[Test]
    public function passthroughRejectsStackedStatement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->compile('COUNT(*) as aggregate; DROP TABLE items--');
    }

    #[Test]
    public function passthroughRejectsUnionInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->compile('1 UNION SELECT password FROM users--');
    }

    #[Test]
    public function passthroughRejectsSleep(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->compile('SLEEP(10)');
    }

    #[Test]
    public function passthroughRejectsNestedFunction(): void
    {
        // SUM(BENCHMARK(...)) — outer is in whitelist, inner arg isn't a column.
        $this->expectException(InvalidArgumentException::class);
        $this->compile('SUM(BENCHMARK(1,1))');
    }

    #[Test]
    public function countWorksEndToEnd(): void
    {
        $this->assertSame(2, $this->db->table('items')->count());
    }

    #[Test]
    public function sumWorksEndToEnd(): void
    {
        $this->assertSame(30.0, $this->db->table('items')->sum('n'));
    }

    #[Test]
    public function distinctCountWorksEndToEnd(): void
    {
        $this->db->insert('items', ['tag' => 'alpha', 'n' => 10]);
        $this->assertSame(2, $this->db->table('items')->count('tag', true));
    }
}
