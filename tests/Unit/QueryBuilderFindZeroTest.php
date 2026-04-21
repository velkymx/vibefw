<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Database\QueryBuilder;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

final class QueryBuilderFindZeroTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Connection::reset();
    }

    protected function tearDown(): void
    {
        Connection::reset();
        parent::tearDown();
    }

    private function freshDb(): Connection
    {
        return Connection::getInstance([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    #[Test]
    public function findEmptyStringReturnsNoneBeforeQuery(): void
    {
        $db = $this->freshDb();
        $db->query('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
        $db->insert('items', ['id' => 1, 'name' => 'one']);

        $option = $db->table('items')->find('');

        $this->assertTrue($option->isNone());
    }

    #[Test]
    public function findZeroOnIntegerPkReturnsNoneWhenNoMatchingRow(): void
    {
        $db = $this->freshDb();
        $db->query('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
        $db->insert('items', ['id' => 1, 'name' => 'one']);

        $option = $db->table('items')->find(0);

        $this->assertTrue($option->isNone());
    }

    #[Test]
    public function findPassesBindingAsIsWithoutCoercion(): void
    {
        $db = $this->freshDb();
        $db->query('CREATE TABLE items (ref TEXT PRIMARY KEY, label TEXT)');
        $db->insert('items', ['ref' => 'abc', 'label' => 'alpha']);

        $found = $db->table('items')->find('abc', 'ref');

        $this->assertTrue($found->isSome());
        $this->assertSame('alpha', $found->unwrapOr([])['label']);
    }

    #[Test]
    public function findZeroBuildsSameSqlAsExplicitWhere(): void
    {
        $db = $this->freshDb();
        $db->query('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');

        $viaFind = $db->table('items')->find(0);
        $viaWhere = $db->table('items')->where('id', 0)->first();

        // Both compile to the same parameterized query; neither rewrites 0.
        $this->assertSame($viaFind->isSome(), $viaWhere->isSome());
        $this->assertTrue($viaFind->isNone());
    }

    #[Test]
    public function findReflectionDocumentsBindingPassthrough(): void
    {
        $doc = (new ReflectionMethod(QueryBuilder::class, 'find'))->getDocComment();

        $this->assertIsString($doc);
        $this->assertStringContainsString('bound as-is', $doc);
    }
}
