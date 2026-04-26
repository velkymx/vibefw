<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * M16: Migration stub does not include `declare(strict_types=1)`.
 *
 * Pre-fix: The generated migration file did not include `declare(strict_types=1);`
 * at the top, while the framework standard requires it.
 *
 * Post-fix: Verified that both migration stub files include `declare(strict_types=1);`
 * at the top, ensuring consistent code style and preventing type coercion bugs.
 */
final class MigrationStubStrictTypesTest extends TestCase
{
    #[Test]
    public function migrationStubHasStrictTypes(): void
    {
        $stubPath = __DIR__ . '/../../../stubs/migration.stub';
        $this->assertFileExists($stubPath, 'Migration stub file should exist');

        $content = file_get_contents($stubPath);
        $this->assertStringContainsString(
            'declare(strict_types=1);',
            $content,
            'Migration stub should include declare(strict_types=1);'
        );
    }

    #[Test]
    public function migrationCreateStubHasStrictTypes(): void
    {
        $stubPath = __DIR__ . '/../../../stubs/migration.create.stub';
        $this->assertFileExists($stubPath, 'Migration create stub file should exist');

        $content = file_get_contents($stubPath);
        $this->assertStringContainsString(
            'declare(strict_types=1);',
            $content,
            'Migration create stub should include declare(strict_types=1);'
        );
    }

    #[Test]
    public function migrationStubHasCorrectNamespace(): void
    {
        $stubPath = __DIR__ . '/../../../stubs/migration.stub';
        $content = file_get_contents($stubPath);

        $this->assertStringContainsString(
            'use Fw\Database\Migration\Migration;',
            $content,
            'Migration stub should use Migration namespace'
        );
        $this->assertStringContainsString(
            'use Fw\Database\Migration\Blueprint;',
            $content,
            'Migration stub should use Blueprint namespace'
        );
    }

    #[Test]
    public function migrationStubHasUpAndDownMethods(): void
    {
        $stubPath = __DIR__ . '/../../../stubs/migration.stub';
        $content = file_get_contents($stubPath);

        $this->assertStringContainsString(
            'public function up(): void',
            $content,
            'Migration stub should have up() method'
        );
        $this->assertStringContainsString(
            'public function down(): void',
            $content,
            'Migration stub should have down() method'
        );
    }

    #[Test]
    public function migrationStubHasCustomizeComments(): void
    {
        $stubPath = __DIR__ . '/../../../stubs/migration.stub';
        $content = file_get_contents($stubPath);

        $this->assertStringContainsString(
            '// CUSTOMIZE:',
            $content,
            'Migration stub should have CUSTOMIZE comments'
        );
    }
}
