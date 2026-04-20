<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Database\QueryBuilder;
use Fw\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class QueryBuilderNullTest extends TestCase
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

        $this->db->query('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(255), tag VARCHAR(50))');
        $this->db->insert('items', ['name' => 'with-tag', 'tag' => 'alpha']);
        $this->db->insert('items', ['name' => 'no-tag-a', 'tag' => null]);
        $this->db->insert('items', ['name' => 'no-tag-b', 'tag' => null]);
    }

    private function query(): QueryBuilder
    {
        return $this->db->table('items');
    }

    #[Test]
    public function whereEqualsNullRendersIsNull(): void
    {
        [$sql, $bindings] = $this->query()->where('tag', null)->toSql();

        $this->assertStringContainsString('IS NULL', $sql);
        $this->assertStringNotContainsString('= ?', $sql);
        $this->assertSame([], $bindings);
    }

    #[Test]
    public function whereEqualsNullMatchesRows(): void
    {
        $rows = $this->query()->where('tag', null)->get();

        $this->assertCount(2, $rows);
    }

    #[Test]
    public function whereExplicitEqualsNullRendersIsNull(): void
    {
        [$sql, $bindings] = $this->query()->where('tag', '=', null)->toSql();

        $this->assertStringContainsString('IS NULL', $sql);
        $this->assertSame([], $bindings);
    }

    #[Test]
    public function whereNotEqualNullRendersIsNotNull(): void
    {
        [$sql, $bindings] = $this->query()->where('tag', '!=', null)->toSql();

        $this->assertStringContainsString('IS NOT NULL', $sql);
        $this->assertSame([], $bindings);
    }

    #[Test]
    public function whereDiamondNullRendersIsNotNull(): void
    {
        [$sql, $bindings] = $this->query()->where('tag', '<>', null)->toSql();

        $this->assertStringContainsString('IS NOT NULL', $sql);
        $this->assertSame([], $bindings);
    }

    #[Test]
    public function whereNotEqualNullMatchesNonNullRow(): void
    {
        $rows = $this->query()->where('tag', '!=', null)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('with-tag', $rows[0]['name']);
    }

    #[Test]
    public function whereNonEqualityOperatorWithNullThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->query()->where('tag', '>', null);
    }

    #[Test]
    public function orWhereEqualsNullRendersOrIsNull(): void
    {
        [$sql] = $this->query()
            ->where('name', 'with-tag')
            ->orWhere('tag', null)
            ->toSql();

        $this->assertStringContainsString('OR', $sql);
        $this->assertStringContainsString('IS NULL', $sql);
    }
}
