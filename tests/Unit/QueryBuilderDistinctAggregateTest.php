<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Database\QueryBuilder;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class QueryBuilderDistinctAggregateTest extends TestCase
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
        $this->db->insert('items', ['tag' => 'alpha', 'n' => 10]);
        $this->db->insert('items', ['tag' => 'beta', 'n' => 20]);
        $this->db->insert('items', ['tag' => null, 'n' => 30]);
    }

    private function query(): QueryBuilder
    {
        return $this->db->table('items');
    }

    #[Test]
    public function distinctFlagDoesNotLeakIntoCountSql(): void
    {
        $qb = $this->query()->distinct()->select('tag');

        [$sql] = $qb->clone()->select('COUNT(*) as aggregate')->toSql();
        // Sanity: base clone with distinct still carries DISTINCT — that's pre-existing.
        $this->assertStringContainsString('DISTINCT', $sql);

        // count() must not inherit the distinct flag — it should emit plain SELECT COUNT(*).
        $this->assertSame(4, $qb->count());
    }

    #[Test]
    public function countWithDistinctColumnUsesCountDistinct(): void
    {
        // Distinct non-null tags: 'alpha', 'beta' → 2.
        $this->assertSame(2, $this->query()->count('tag', true));
    }

    #[Test]
    public function countWithColumnOnlyCountsNonNull(): void
    {
        // COUNT(tag) skips the NULL row → 3.
        $this->assertSame(3, $this->query()->count('tag'));
    }

    #[Test]
    public function sumWithDistinctDeduplicatesBeforeSumming(): void
    {
        // Raw sum: 10+10+20+30 = 70. DISTINCT sum: 10+20+30 = 60.
        $this->assertSame(70.0, $this->query()->sum('n'));
        $this->assertSame(60.0, $this->query()->sum('n', true));
    }

    #[Test]
    public function avgWithDistinctDeduplicatesBeforeAveraging(): void
    {
        // DISTINCT values: 10, 20, 30 → avg 20.
        $this->assertSame(20.0, $this->query()->avg('n', true));
    }

    #[Test]
    public function distinctQueryDoesNotBreakMinMax(): void
    {
        $qb = $this->query()->distinct()->select('n');
        $this->assertSame(10, (int) $qb->min('n'));
        $this->assertSame(30, (int) $qb->max('n'));
    }
}
