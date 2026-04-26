<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Database;

use Fw\Database\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Item H15 — MySQL init command must not force a collation by default.
 * The old code forced {charset}_unicode_ci, which caused implicit
 * collation conversions on joins/indexes when the server or tables
 * use a different default (e.g. utf8mb4_0900_ai_ci in MySQL 8).
 *
 * The fixed code uses SET NAMES {charset} with no COLLATE clause
 * by default, and only adds COLLATE when DB_COLLATION is explicitly
 * configured.
 */
final class ConnectionMysqlCollationTest extends TestCase
{
    #[Test]
    public function mysqlInitCommandDoesNotForceCollationByDefault(): void
    {
        $body = $this->constructorBody();

        $this->assertStringNotContainsString(
            '_unicode_ci',
            $body,
            'Connection must not force _unicode_ci — use server default collation unless DB_COLLATION is set.',
        );
    }

    #[Test]
    public function mysqlInitCommandUsesSetNamesWithoutCollateWhenNoCollationConfigured(): void
    {
        $body = $this->constructorBody();

        $this->assertStringContainsString(
            'SET NAMES',
            $body,
            'Connection must use SET NAMES for charset initialization.',
        );
        $this->assertStringContainsString(
            "collation",
            $body,
            'Connection must check collation config before adding COLLATE clause.',
        );
    }

    private function constructorBody(): string
    {
        $ref = new ReflectionMethod(Connection::class, '__construct');
        $file = file($ref->getFileName());
        $start = $ref->getStartLine() - 1;
        $end = $ref->getEndLine();
        return implode('', array_slice($file, $start, $end - $start));
    }
}
