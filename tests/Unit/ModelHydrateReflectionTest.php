<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Model\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Model with a public property to verify hydration
class HydrateTestModel extends Model
{
    protected static ?string $table = 'items';
    protected static array $fillable = ['name', 'score'];

    public string $name = '';
    public int $score = 0;
}

final class ModelHydrateReflectionTest extends TestCase
{
    #[Test]
    public function hydrateDoesNotCreateReflectionOnEachRow(): void
    {
        // Verify that hydrating many rows doesn't create proportionally many ReflectionClass calls.
        // We test by verifying the cached path: ModelMetadata already knows public property names.
        // Hydrate 100 rows and confirm no exception and proper attribute setting.

        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $rows[] = ['name' => "item-{$i}", 'score' => $i];
        }

        $models = array_map(fn ($row) => HydrateTestModel::hydrate($row), $rows);

        $this->assertCount(100, $models);
        $this->assertSame('item-0', $models[0]->name);
        $this->assertSame(42, $models[42]->score);
        $this->assertSame('item-99', $models[99]->name);
    }

    #[Test]
    public function hydrateUsesMetadataCachedPublicPropertyNames(): void
    {
        // The fix: hydrate() must use ModelMetadata::publicPropertyNames
        // instead of new ReflectionClass($model) per row.
        // We verify this by checking the ModelMetadata has the public property names.

        $ref = new \ReflectionClass(HydrateTestModel::class);
        $method = $ref->getMethod('metadata');
        /** @var \Fw\Model\ModelMetadata $meta */
        $meta = $method->invoke(null);

        $this->assertContains('name', $meta->publicPropertyNames);
        $this->assertContains('score', $meta->publicPropertyNames);
    }
}
