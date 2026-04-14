<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;

/**
 * C5: Connection::delete() and ::update() must reject empty $where / $data
 * instead of producing malformed SQL that causes cryptic PDOExceptions.
 */
final class ConnectionSafetyTest extends TestCase
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
            CREATE TABLE items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL
            )
        ");

        $this->db->insert('items', ['name' => 'Alpha']);
        $this->db->insert('items', ['name' => 'Beta']);
    }

    // ── delete() ────────────────────────────────────────────────────────

    #[Test]
    public function deleteWithEmptyWhereThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/where/i');

        $this->db->delete('items', []);
    }

    #[Test]
    public function deleteWithWhereDeletesMatchingRow(): void
    {
        $affected = $this->db->delete('items', ['name' => 'Alpha']);

        $this->assertSame(1, $affected);
        $this->assertSame(1, $this->db->table('items')->count());
    }

    // ── update() ────────────────────────────────────────────────────────

    #[Test]
    public function updateWithEmptyWhereThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/where/i');

        $this->db->update('items', ['name' => 'Changed'], []);
    }

    #[Test]
    public function updateWithEmptyDataThrowsLogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/data/i');

        $this->db->update('items', [], ['id' => 1]);
    }

    #[Test]
    public function updateWithWhereUpdatesMatchingRow(): void
    {
        $this->db->update('items', ['name' => 'Alpha-updated'], ['name' => 'Alpha']);

        $updated = $this->db->table('items')->where('name', '=', 'Alpha-updated')->count();
        $this->assertSame(1, $updated);
    }
}
