<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Database\QueryBuilder;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class QueryBuilderPaginateWindowTest extends TestCase
{
    protected ?Connection $db = null;

    protected function setUp(): void
    {
        parent::setUp();
        Connection::reset();

        $this->db = Connection::getInstance([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'logging' => true,
        ]);

        $this->db->query('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, tag TEXT)');
        for ($i = 1; $i <= 23; $i++) {
            $this->db->insert('items', ['name' => "item-{$i}", 'tag' => $i % 2 === 0 ? 'even' : 'odd']);
        }
    }

    private function query(): QueryBuilder
    {
        return $this->db->table('items');
    }

    private function countSelects(int $before): int
    {
        $log = array_slice($this->db->getQueryLog(), $before);
        return count(array_filter(
            $log,
            fn (array $entry) => str_starts_with(ltrim($entry['sql']), 'SELECT'),
        ));
    }

    #[Test]
    public function windowPaginateReturnsSameShapeAsTwoQueryPaginate(): void
    {
        $two = $this->query()->orderBy('id', 'asc')->paginate(10, 2);
        $one = $this->query()->orderBy('id', 'asc')->paginate(10, 2, true);

        $this->assertSame($two['total'], $one['total']);
        $this->assertSame($two['per_page'], $one['per_page']);
        $this->assertSame($two['current_page'], $one['current_page']);
        $this->assertSame($two['last_page'], $one['last_page']);
        $this->assertSame(count($two['items']), count($one['items']));
    }

    #[Test]
    public function windowPaginateIssuesOneQuery(): void
    {
        $checkpoint = count($this->db->getQueryLog());
        $this->query()->orderBy('id', 'asc')->paginate(10, 1, true);
        $this->assertSame(1, $this->countSelects($checkpoint), 'Window pagination must issue a single SELECT.');
    }

    #[Test]
    public function twoQueryPaginateIssuesTwoQueries(): void
    {
        $checkpoint = count($this->db->getQueryLog());
        $this->query()->orderBy('id', 'asc')->paginate(10, 1);
        $this->assertSame(2, $this->countSelects($checkpoint), 'Default pagination must issue count + items queries.');
    }

    #[Test]
    public function windowPaginateRespectsWhereClause(): void
    {
        $result = $this->query()->where('tag', 'even')->orderBy('id', 'asc')->paginate(5, 1, true);
        $this->assertSame(11, $result['total']);
        $this->assertCount(5, $result['items']);
        $this->assertSame(3, $result['last_page']);
    }

    #[Test]
    public function windowPaginateEmptyResultHasZeroTotal(): void
    {
        $result = $this->query()->where('tag', 'missing')->paginate(5, 1, true);
        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['last_page']);
    }

    #[Test]
    public function windowPaginateStripsInternalTotalColumnFromItems(): void
    {
        $result = $this->query()->orderBy('id', 'asc')->paginate(3, 1, true);
        foreach ($result['items'] as $row) {
            foreach (array_keys($row) as $key) {
                $this->assertStringNotContainsString('paginate', (string) $key);
            }
        }
    }

    #[Test]
    public function windowPaginateFallsBackWhenGroupByPresent(): void
    {
        $checkpoint = count($this->db->getQueryLog());
        // GROUP BY changes aggregate semantics — window mode must fall back to
        // the two-query path rather than produce wrong totals.
        $result = $this->query()->groupBy('tag')->paginate(10, 1, true);
        $this->assertSame(2, $this->countSelects($checkpoint));
        $this->assertArrayHasKey('total', $result);
    }
}
