<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Item #10: Connection DSN is built via string interpolation. A compromised
 * or malformed config that slips a ';' or control char into host/database/
 * charset would inject extra DSN parameters or SQL into the init command.
 *
 * These tests lock the constructor into rejecting anything that can't be
 * a legal DSN component — defense-in-depth on top of config validation.
 */
final class ConnectionDsnValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Connection::reset();
    }

    #[Test]
    public function rejectsHostContainingSemicolon(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid DSN component');

        new Connection([
            'driver'   => 'mysql',
            'host'     => 'localhost;user=root',
            'database' => 'app',
        ]);
    }

    #[Test]
    public function rejectsDatabaseContainingNewline(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid DSN component');

        new Connection([
            'driver'   => 'mysql',
            'host'     => 'localhost',
            'database' => "app\r\nINJECT",
        ]);
    }

    #[Test]
    public function rejectsCharsetWithQuote(): void
    {
        // charset is interpolated into the MySQL init command
        //   SET NAMES $charset COLLATE {$charset}_unicode_ci
        // so anything non-alphanumeric is a SQL-injection vector.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid DSN component');

        new Connection([
            'driver'   => 'mysql',
            'host'     => 'localhost',
            'database' => 'app',
            'charset'  => "utf8mb4'; DROP TABLE users--",
        ]);
    }

    #[Test]
    public function rejectsNonNumericPort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid DSN component');

        new Connection([
            'driver'   => 'mysql',
            'host'     => 'localhost',
            'port'     => '3306;user=root',
            'database' => 'app',
        ]);
    }

    #[Test]
    public function acceptsSqliteFilesystemPath(): void
    {
        // SQLite's "database" is a filesystem path — must allow slashes and
        // dots. Only control chars / nulls should be rejected.
        $this->expectNotToPerformAssertions();

        new Connection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    #[Test]
    public function acceptsNormalMysqlConfig(): void
    {
        // Sanity: the guard must not break real configs. We can't actually
        // connect here (no server), but the DSN-validation step runs before
        // the PDO handshake — so we expect a PDOException, not our
        // InvalidArgumentException.
        try {
            new Connection([
                'driver'   => 'mysql',
                'host'     => 'nonexistent-host-for-test.invalid',
                'port'     => 3306,
                'database' => 'app_db',
                'username' => 'user',
                'password' => 'pw',
                'charset'  => 'utf8mb4',
            ]);
            $this->fail('Expected PDOException from unreachable host');
        } catch (InvalidArgumentException $e) {
            $this->fail('DSN validator wrongly rejected a legal config: ' . $e->getMessage());
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
    }
}
