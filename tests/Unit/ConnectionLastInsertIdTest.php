<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

final class ConnectionLastInsertIdTest extends TestCase
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

        $this->db->query('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(64))');
    }

    #[Test]
    public function lastInsertIdHasStringReturnType(): void
    {
        $rm = new ReflectionMethod(Connection::class, 'lastInsertId');
        $return = $rm->getReturnType();

        $this->assertNotNull($return);
        $this->assertSame('string', (string) $return);
    }

    #[Test]
    public function lastInsertIdReturnsStringAfterInsert(): void
    {
        $this->db->insert('widgets', ['name' => 'alpha']);
        $id = $this->db->lastInsertId();

        $this->assertIsString($id);
        $this->assertSame('1', $id);
    }

    #[Test]
    public function lastInsertIdReflectsMostRecentRow(): void
    {
        $this->db->insert('widgets', ['name' => 'one']);
        $this->db->insert('widgets', ['name' => 'two']);
        $this->db->insert('widgets', ['name' => 'three']);

        $this->assertSame('3', $this->db->lastInsertId());
    }
}
