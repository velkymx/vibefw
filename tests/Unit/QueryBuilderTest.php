<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Database\QueryBuilder;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class QueryBuilderTest extends TestCase
{
    protected ?Connection $db = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset singleton for clean test
        Connection::reset();

        $this->db = Connection::getInstance([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        // Create test table
        $this->db->query("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                role VARCHAR(50) DEFAULT 'user',
                age INTEGER,
                active INTEGER DEFAULT 1,
                created_at DATETIME
            )
        ");

        // Insert test data
        $this->db->insert('users', ['name' => 'John Doe', 'email' => 'john@example.com', 'role' => 'admin', 'age' => 30, 'active' => 1]);
        $this->db->insert('users', ['name' => 'Jane Smith', 'email' => 'jane@example.com', 'role' => 'user', 'age' => 25, 'active' => 1]);
        $this->db->insert('users', ['name' => 'Bob Wilson', 'email' => 'bob@example.com', 'role' => 'user', 'age' => 35, 'active' => 0]);
    }

    private function query(): QueryBuilder
    {
        return $this->db->table('users');
    }

    public function testGetReturnsAllRows(): void
    {
        $users = $this->query()->get();

        $this->assertCount(3, $users);
    }

    public function testSelectSpecificColumns(): void
    {
        $users = $this->query()->select('name', 'email')->get();

        $this->assertArrayHasKey('name', $users[0]);
        $this->assertArrayHasKey('email', $users[0]);
    }

    public function testSelectWithArraySyntax(): void
    {
        $users = $this->query()->select(['name', 'email'])->get();

        $this->assertArrayHasKey('name', $users[0]);
        $this->assertArrayHasKey('email', $users[0]);
    }

    public function testWhereFiltersResults(): void
    {
        $users = $this->query()->where('role', 'admin')->get();

        $this->assertCount(1, $users);
        $this->assertEquals('John Doe', $users[0]['name']);
    }

    public function testWhereWithOperator(): void
    {
        $users = $this->query()->where('age', '>', 28)->get();

        $this->assertCount(2, $users);
    }

    public function testWhereWithEqualsOperator(): void
    {
        $users = $this->query()->where('age', '=', 25)->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Jane Smith', $users[0]['name']);
    }

    public function testOrWhereFiltersResults(): void
    {
        $users = $this->query()
            ->where('role', 'admin')
            ->orWhere('age', '<', 30)
            ->get();

        $this->assertCount(2, $users);
    }

    public function testWhereInFiltersResults(): void
    {
        $users = $this->query()->whereIn('name', ['John Doe', 'Jane Smith'])->get();

        $this->assertCount(2, $users);
    }

    public function testWhereInRejectsEmptyArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('whereIn requires at least one value');

        $this->query()->whereIn('name', []);
    }

    public function testWhereInRejectsNonScalarValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('whereIn values must be scalar');

        $this->query()->whereIn('name', ['ok', ['nested']]);
    }

    public function testWhereInBindingsMatchPlaceholderCount(): void
    {
        [$sql, $bindings] = $this->query()->whereIn('id', [1, 2, 3, 4])->toSql();

        $this->assertSame(4, substr_count($sql, '?'));
        $this->assertCount(4, $bindings);
        $this->assertSame([1, 2, 3, 4], array_values($bindings));
    }

    public function testWhereNullFiltersResults(): void
    {
        // Update one user to have null age
        $this->db->query("UPDATE users SET age = NULL WHERE name = 'Bob Wilson'");

        $users = $this->query()->whereNull('age')->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Bob Wilson', $users[0]['name']);
    }

    public function testWhereNotNullFiltersResults(): void
    {
        // Update one user to have null age
        $this->db->query("UPDATE users SET age = NULL WHERE name = 'Bob Wilson'");

        $users = $this->query()->whereNotNull('age')->get();

        $this->assertCount(2, $users);
    }

    public function testWhereBetweenFiltersResults(): void
    {
        $users = $this->query()->whereBetween('age', 24, 31)->get();

        $this->assertCount(2, $users);
    }

    public function testFirstReturnsSomeForExistingRow(): void
    {
        $opt = $this->query()->first();

        $this->assertTrue($opt->isSome());
        $user = $opt->unwrapOr([]);
        $this->assertArrayHasKey('name', $user);
    }

    public function testFirstReturnsNoneForNoResults(): void
    {
        $opt = $this->query()->where('email', 'nonexistent@example.com')->first();

        $this->assertTrue($opt->isNone());
    }

    public function testFindByIdReturnsOption(): void
    {
        $opt = $this->query()->find(1);

        $this->assertTrue($opt->isSome());
        $user = $opt->unwrapOr([]);
        $this->assertEquals('John Doe', $user['name']);
    }

    public function testFindReturnsNoneForInvalidId(): void
    {
        $opt = $this->query()->find(999);

        $this->assertTrue($opt->isNone());
    }

    #[Test]
    public function testFindReturnsNoneForEmptyStringId(): void
    {
        // Empty string must return none rather than producing WHERE id = ''
        $opt = $this->query()->find('');

        $this->assertTrue($opt->isNone());
    }

    public function testCountReturnsNumberOfRows(): void
    {
        $count = $this->query()->count();

        $this->assertEquals(3, $count);
    }

    public function testCountWithWhereClause(): void
    {
        $count = $this->query()->where('role', 'user')->count();

        $this->assertEquals(2, $count);
    }

    public function testExistsReturnsTrueForMatchingRows(): void
    {
        $exists = $this->query()->where('email', 'john@example.com')->exists();

        $this->assertTrue($exists);
    }

    public function testExistsReturnsFalseForNoMatches(): void
    {
        $exists = $this->query()->where('email', 'nonexistent@example.com')->exists();

        $this->assertFalse($exists);
    }

    public function testSumCalculatesTotal(): void
    {
        $sum = $this->query()->sum('age');

        $this->assertEquals(90.0, $sum);
    }

    public function testAvgCalculatesAverage(): void
    {
        $avg = $this->query()->avg('age');

        $this->assertEquals(30.0, $avg);
    }

    public function testMaxReturnsMaxValue(): void
    {
        $max = $this->query()->max('age');

        $this->assertEquals(35, $max);
    }

    public function testMinReturnsMinValue(): void
    {
        $min = $this->query()->min('age');

        $this->assertEquals(25, $min);
    }

    public function testOrderByAscending(): void
    {
        $users = $this->query()->orderBy('age', 'ASC')->get();

        $this->assertEquals('Jane Smith', $users[0]['name']);
        $this->assertEquals('Bob Wilson', $users[2]['name']);
    }

    public function testOrderByDescending(): void
    {
        $users = $this->query()->orderBy('age', 'DESC')->get();

        $this->assertEquals('Bob Wilson', $users[0]['name']);
        $this->assertEquals('Jane Smith', $users[2]['name']);
    }

    public function testMultipleOrderBy(): void
    {
        $users = $this->query()
            ->orderBy('role', 'ASC')
            ->orderBy('name', 'ASC')
            ->get();

        $this->assertEquals('John Doe', $users[0]['name']); // admin
    }

    public function testLimitRestricsResults(): void
    {
        $users = $this->query()->limit(2)->get();

        $this->assertCount(2, $users);
    }

    public function testOffsetSkipsRows(): void
    {
        $users = $this->query()->orderBy('id', 'ASC')->offset(1)->limit(2)->get();

        $this->assertCount(2, $users);
        $this->assertEquals('Jane Smith', $users[0]['name']);
    }

    public function testDistinctRemovesDuplicates(): void
    {
        $roles = $this->query()->select('role')->distinct()->get();

        $this->assertCount(2, $roles);
    }

    public function testInsertAddsRow(): void
    {
        $id = $this->query()->insert([
            'name' => 'New User',
            'email' => 'new@example.com',
            'role' => 'user',
            'age' => 28,
        ]);

        $this->assertGreaterThan(0, $id);
        $this->assertEquals(4, $this->query()->count());
    }

    public function testUpdateModifiesRows(): void
    {
        $affected = $this->query()
            ->where('email', 'john@example.com')
            ->update(['name' => 'John Updated']);

        $this->assertEquals(1, $affected);

        $user = $this->query()->where('email', 'john@example.com')->first()->unwrapOr([]);
        $this->assertEquals('John Updated', $user['name']);
    }

    public function testDeleteRemovesRows(): void
    {
        $affected = $this->query()
            ->where('email', 'bob@example.com')
            ->delete();

        $this->assertEquals(1, $affected);
        $this->assertEquals(2, $this->query()->count());
    }

    public function testPaginateReturnsStructuredResult(): void
    {
        $result = $this->query()->paginate(2, 1);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('per_page', $result);
        $this->assertArrayHasKey('current_page', $result);
        $this->assertArrayHasKey('last_page', $result);

        $this->assertCount(2, $result['items']);
        $this->assertEquals(3, $result['total']);
        $this->assertEquals(2, $result['per_page']);
        $this->assertEquals(1, $result['current_page']);
        $this->assertEquals(2, $result['last_page']);
    }

    public function testPaginateSecondPage(): void
    {
        $result = $this->query()->orderBy('id', 'ASC')->paginate(2, 2);

        $this->assertCount(1, $result['items']);
        $this->assertEquals(2, $result['current_page']);
    }

    public function testToSqlReturnsQueryAndBindings(): void
    {
        [$sql, $bindings] = $this->query()
            ->where('role', 'admin')
            ->where('active', 1)
            ->toSql();

        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('WHERE', $sql);
        $this->assertCount(2, $bindings);
        $this->assertEquals('admin', $bindings[0]);
        $this->assertEquals(1, $bindings[1]);
    }

    public function testQueryBuilderIsImmutable(): void
    {
        $query1 = $this->query();
        $query2 = $query1->where('role', 'admin');

        $this->assertNotSame($query1, $query2);

        // Original query should still return all rows
        $this->assertCount(3, $query1->get());
        $this->assertCount(1, $query2->get());
    }

    public function testChainedWhereConditions(): void
    {
        $users = $this->query()
            ->where('role', 'user')
            ->where('active', 1)
            ->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Jane Smith', $users[0]['name']);
    }
}
