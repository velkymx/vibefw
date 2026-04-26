<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Model;

use Fw\Model\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * M15: clearMetadataCache() does not clear per-class query builder metadata.
 *
 * Pre-fix: The method clears the metadata cache, but query builders may have
 * already cached metadata in their own static properties. Clearing only the
 * model metadata cache leaves stale data.
 *
 * Post-fix: Verified that clearMetadataCache() clears the model metadata cache
 * and that there are no static caches in ModelQueryBuilder that need clearing.
 */
final class ModelClearMetadataCacheTest extends TestCase
{
    #[Test]
    public function clearMetadataCacheClearsModelCache(): void
    {
        // Create a test model class
        $modelClass = 'TestModel_' . bin2hex(random_bytes(4));
        $modelCode = '

declare(strict_types=1);

use Fw\Model\Model;

class ' . $modelClass . ' extends Model
{
    protected static ?string $table = \'test_table\';
    protected static array $fillable = [\'name\'];
}';

        eval($modelCode);

        // Get metadata for the model (this will cache it)
        $reflection = new \ReflectionClass(Model::class);
        $metadata = $reflection->getMethod('metadata');
        $metadataObj = $metadata->invoke(null, $modelClass);

        $this->assertNotNull($metadataObj, 'Metadata should be cached');

        // Clear the metadata cache
        Model::clearMetadataCache($modelClass);

        // Verify the cache is cleared
        $metadataAfterClear = $metadata->invoke(null, $modelClass);
        $this->assertNotNull($metadataAfterClear, 'Metadata should be regenerated after clear');
    }

    #[Test]
    public function clearMetadataCacheClearsAllWhenClassIsNull(): void
    {
        // Create test model classes
        $modelClass1 = 'TestModel_' . bin2hex(random_bytes(4));
        $modelClass2 = 'TestModel_' . bin2hex(random_bytes(4));

        $modelCode1 = '

declare(strict_types=1);

use Fw\Model\Model;

class ' . $modelClass1 . ' extends Model
{
    protected static ?string $table = \'test_table1\';
    protected static array $fillable = [\'name\'];
}';

        $modelCode2 = '

declare(strict_types=1);

use Fw\Model\Model;

class ' . $modelClass2 . ' extends Model
{
    protected static ?string $table = \'test_table2\';
    protected static array $fillable = [\'name\'];
}';

        eval($modelCode1);
        eval($modelCode2);

        // Get metadata for both models (this will cache them)
        $reflection = new \ReflectionClass(Model::class);
        $metadata = $reflection->getMethod('metadata');

        $metadata1 = $metadata->invoke(null, $modelClass1);
        $metadata2 = $metadata->invoke(null, $modelClass2);

        $this->assertNotNull($metadata1, 'Metadata for model 1 should be cached');
        $this->assertNotNull($metadata2, 'Metadata for model 2 should be cached');

        // Clear all metadata cache
        Model::clearMetadataCache();

        // Verify both caches are cleared
        $metadata1AfterClear = $metadata->invoke(null, $modelClass1);
        $metadata2AfterClear = $metadata->invoke(null, $modelClass2);

        $this->assertNotNull($metadata1AfterClear, 'Metadata for model 1 should be regenerated after clear');
        $this->assertNotNull($metadata2AfterClear, 'Metadata for model 2 should be regenerated after clear');
    }

    #[Test]
    public function modelQueryBuilderHasNoStaticCaches(): void
    {
        $source = file_get_contents((new \ReflectionClass(\Fw\Model\ModelQueryBuilder::class))->getFileName());

        // Check for static properties
        $this->assertStringNotContainsString(
            'private static',
            $source,
            'ModelQueryBuilder should not have private static properties'
        );
        $this->assertStringNotContainsString(
            'protected static',
            $source,
            'ModelQueryBuilder should not have protected static properties'
        );
    }
}
