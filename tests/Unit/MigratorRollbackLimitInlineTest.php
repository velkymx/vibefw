<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Migration\Migrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Item #45: `Migrator::getMigrationsToRollback()` bound the LIMIT
 * value as a `?` parameter. Some PDO drivers (and specific MySQL
 * ATTR_EMULATE_PREPARES configurations) reject bound integers in
 * LIMIT, turning rollback into a runtime error on otherwise valid
 * steps counts.
 *
 * [R49] inlines the rollback steps as `(int)` into the SQL string.
 * `$steps` is already a typed `?int` parameter on
 * `getMigrationsToRollback()`, so the cast is safe — no user input
 * reaches the SQL string.
 *
 * Tests verify at the source level:
 *   - no `LIMIT ?` in the rollback SQL path
 *   - `LIMIT ' . (int)` (or equivalent cast) is used instead
 *   - method still takes `?int $steps`
 */
final class MigratorRollbackLimitInlineTest extends TestCase
{
    private function methodBody(string $method): string
    {
        $rm = new ReflectionMethod(Migrator::class, $method);
        $start = (int) $rm->getStartLine();
        $end = (int) $rm->getEndLine();
        $lines = file((string) $rm->getFileName()) ?: [];
        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    #[Test]
    public function rollbackQueryDoesNotBindLimitAsParameter(): void
    {
        $body = $this->methodBody('getMigrationsToRollback');

        $this->assertDoesNotMatchRegularExpression(
            '/LIMIT\s*\?/',
            $body,
            'LIMIT must not use a bound parameter — some PDO drivers reject that.'
        );
    }

    #[Test]
    public function rollbackQueryInlinesCastedIntInLimit(): void
    {
        $body = $this->methodBody('getMigrationsToRollback');

        $this->assertMatchesRegularExpression(
            '/LIMIT\s*\'\s*\.\s*\(int\)/',
            $body,
            'LIMIT must be inlined via an (int) cast to avoid driver-specific binding issues.'
        );
    }

    #[Test]
    public function rollbackStepsIsStillNullableInt(): void
    {
        $rm = new ReflectionMethod(Migrator::class, 'getMigrationsToRollback');
        $params = $rm->getParameters();
        $this->assertCount(1, $params);

        $type = $params[0]->getType();
        $this->assertNotNull($type);
        $this->assertSame('?int', (string) $type);
    }
}
