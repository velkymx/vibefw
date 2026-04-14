<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Model\Model;
use Fw\Support\Result;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SaveResultTestModel extends Model
{
    protected static ?string $table = 'save_result_items';
    protected static bool $timestamps = false;
    protected static array $fillable = ['name'];
}

final class ModelSaveResultTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->query('CREATE TABLE save_result_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        Model::setConnection($this->db);
    }

    protected function tearDown(): void
    {
        Model::resetConnection();
    }

    #[Test]
    public function saveReturnsResultType(): void
    {
        $model = new SaveResultTestModel();
        $model->fill(['name' => 'test']);

        $result = $model->save();

        $this->assertInstanceOf(Result::class, $result);
    }

    #[Test]
    public function saveReturnsOkWithModelOnSuccess(): void
    {
        $model = new SaveResultTestModel();
        $model->fill(['name' => 'hello']);

        $result = $model->save();

        $this->assertTrue($result->isOk());
        $saved = $result->unwrapOr(null);
        $this->assertInstanceOf(SaveResultTestModel::class, $saved);
        $this->assertSame('hello', $saved->name);
    }
}
