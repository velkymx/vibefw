<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Model\Model;
use Fw\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Test Model for updateOrCreate tests.
 */
final class UocTestModel extends Model
{
    protected static ?string $table = 'items';
    protected static array $fillable = ['name', 'category'];
    protected static bool $incrementing = true;
    protected static string $keyType = 'int';
    protected static bool $timestamps = false;
}

/**
 * H3: Model::updateOrCreate([]) must throw InvalidArgumentException
 * on empty $attributes, not crash with a confusing null-key error.
 */
final class ModelUpdateOrCreateTest extends TestCase
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
                name VARCHAR(255) NOT NULL,
                category VARCHAR(255)
            )
        ");

        Model::setConnection($this->db);
    }

    #[Test]
    public function updateOrCreateWithEmptyAttributesThrowsInvalidArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/attribute/i');

        UocTestModel::updateOrCreate([], ['name' => 'foo']);
    }

    #[Test]
    public function updateOrCreateInsertsWhenNoMatchFound(): void
    {
        $model = UocTestModel::updateOrCreate(
            ['name' => 'Alpha'],
            ['category' => 'A'],
        );

        $this->assertSame('Alpha', $model->name);
        $this->assertSame('A', $model->category);
        $this->assertSame(1, $this->db->table('items')->count());
    }

    #[Test]
    public function updateOrCreateUpdatesExistingMatch(): void
    {
        $this->db->insert('items', ['name' => 'Alpha', 'category' => 'old']);

        UocTestModel::updateOrCreate(
            ['name' => 'Alpha'],
            ['category' => 'new'],
        );

        $row = $this->db->table('items')->where('name', '=', 'Alpha')->first()->unwrapOrElse(fn () => throw new \RuntimeException('Item not found'));
        $this->assertSame('new', $row['category']);
        $this->assertSame(1, $this->db->table('items')->count());
    }
}
