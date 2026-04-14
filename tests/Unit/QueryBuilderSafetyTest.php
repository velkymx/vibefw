<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;

/**
 * C4: QueryBuilder::delete() and ::update() must refuse to operate
 * without a WHERE clause to prevent accidental mass data loss.
 */
final class QueryBuilderSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Connection::reset();
        $this->db = Connection::getInstance([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->db->query("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL
            )
        ");

        $this->db->insert('users', ['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->db->insert('users', ['name' => 'Bob',   'email' => 'bob@example.com']);
    }

    // ── delete() ────────────────────────────────────────────────────────

    #[Test]
    public function deleteWithoutWhereThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/where/i');

        $this->db->table('users')->delete();
    }

    #[Test]
    public function deleteWithWhereDeletesMatchingRowsOnly(): void
    {
        $deleted = $this->db->table('users')->where('name', '=', 'Alice')->delete();

        $this->assertSame(1, $deleted);
        $remaining = $this->db->table('users')->count();
        $this->assertSame(1, $remaining);
    }

    #[Test]
    public function deleteAllRowsRequiresExplicitWhereTrue(): void
    {
        // After fix we need a way to do intentional bulk delete.
        // The conventional escape hatch: where('1', '=', '1') or a dedicated deleteAll().
        // For now verify that a where() call allowing it still works.
        $this->db->table('users')->where('1', '=', '1')->delete();

        $this->assertSame(0, $this->db->table('users')->count());
    }

    // ── update() ────────────────────────────────────────────────────────

    #[Test]
    public function updateWithoutWhereThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/where/i');

        $this->db->table('users')->update(['name' => 'Hacked']);
    }

    #[Test]
    public function updateWithWhereUpdatesMatchingRowsOnly(): void
    {
        $this->db->table('users')
            ->where('name', '=', 'Alice')
            ->update(['name' => 'Alicia']);

        $alicia = $this->db->table('users')->where('name', '=', 'Alicia')->count();
        $alice  = $this->db->table('users')->where('name', '=', 'Alice')->count();

        $this->assertSame(1, $alicia);
        $this->assertSame(0, $alice);
    }
}
