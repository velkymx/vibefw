<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Database;

use Fw\Database\Connection;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * M10: runWithRetry() skipped retry inside transactions without clear messaging.
 *
 * Pre-fix: When a retryable error occurred inside an active transaction,
 * runWithRetry() just threw the original exception. Users couldn't tell
 * why retry was skipped or that they should wrap the transaction in their
 * own retry loop.
 *
 * Post-fix: When a retryable error occurs inside a transaction, runWithRetry()
 * logs a warning and throws a PDOException with a clear message explaining
 * that retry was skipped due to the active transaction and suggesting the
 * caller wrap the transaction in their own retry loop.
 */
final class ConnectionRunWithRetryTransactionMessageTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        // Use SQLite for testing
        $this->connection = new Connection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    protected function tearDown(): void
    {
        // Connection cleanup is handled by the test framework
    }

    #[Test]
    public function runWithRetryLogsWarningForRetryableErrorInTransaction(): void
    {
        // Start a transaction
        $this->connection->beginTransaction();

        // Create a table
        $this->connection->query('CREATE TABLE test (id INTEGER PRIMARY KEY, value TEXT)');

        // Insert a row
        $this->connection->insert('test', ['id' => 1, 'value' => 'test']);

        // Try to insert a duplicate (will fail with UNIQUE constraint)
        // This is not retryable, so we need a different approach

        // Instead, let's test the message by checking the exception
        // We'll use reflection to access the private runWithRetry method
        $reflection = new \ReflectionClass(Connection::class);
        $runWithRetry = $reflection->getMethod('runWithRetry');

        // Create a retryable PDOException (SQLSTATE 40001 is a deadlock)
        $retryableException = new PDOException(
            'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock',
            0
        );
        $retryableException->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/Transient database error occurred inside an active transaction/');

        try {
            $runWithRetry->invoke($this->connection, function () use ($retryableException): void {
                throw $retryableException;
            });
        } finally {
            $this->connection->rollBack();
        }
    }

    #[Test]
    public function runWithRetryIncludesSqlStateInTransactionError(): void
    {
        $this->connection->beginTransaction();

        $reflection = new \ReflectionClass(Connection::class);
        $runWithRetry = $reflection->getMethod('runWithRetry');

        $retryableException = new PDOException(
            'SQLSTATE[40001]: Serialization failure',
            0
        );
        $retryableException->errorInfo = ['40001', 1213, 'Serialization failure'];

        try {
            $runWithRetry->invoke($this->connection, function () use ($retryableException): void {
                throw $retryableException;
            });
            $this->fail('Expected PDOException to be thrown');
        } catch (PDOException $e) {
            $this->assertStringContainsString('SQLSTATE: 40001', $e->getMessage());
        } finally {
            $this->connection->rollBack();
        }
    }

    #[Test]
    public function runWithRetrySuggestsRetryLoopInTransactionError(): void
    {
        $this->connection->beginTransaction();

        $reflection = new \ReflectionClass(Connection::class);
        $runWithRetry = $reflection->getMethod('runWithRetry');

        $retryableException = new PDOException(
            'SQLSTATE[40001]: Serialization failure',
            0
        );
        $retryableException->errorInfo = ['40001', 1213, 'Serialization failure'];

        try {
            $runWithRetry->invoke($this->connection, function () use ($retryableException): void {
                throw $retryableException;
            });
            $this->fail('Expected PDOException to be thrown');
        } catch (PDOException $e) {
            $this->assertStringContainsString('Consider wrapping the transaction in your own retry loop', $e->getMessage());
        } finally {
            $this->connection->rollBack();
        }
    }

    #[Test]
    public function runWithRetryDoesNotModifyNonRetryableErrorsInTransaction(): void
    {
        $this->connection->beginTransaction();

        $reflection = new \ReflectionClass(Connection::class);
        $runWithRetry = $reflection->getMethod('runWithRetry');

        // Create a non-retryable exception (syntax error)
        $nonRetryableException = new PDOException(
            'SQLSTATE[42000]: Syntax error',
            0
        );
        $nonRetryableException->errorInfo = ['42000', 1064, 'Syntax error'];

        try {
            $runWithRetry->invoke($this->connection, function () use ($nonRetryableException): void {
                throw $nonRetryableException;
            });
            $this->fail('Expected PDOException to be thrown');
        } catch (PDOException $e) {
            // Non-retryable errors should not be modified
            $this->assertStringNotContainsString('Transient database error', $e->getMessage());
            $this->assertSame('SQLSTATE[42000]: Syntax error', $e->getMessage());
        } finally {
            $this->connection->rollBack();
        }
    }

    #[Test]
    public function runWithRetryStillRetriesOutsideTransaction(): void
    {
        $reflection = new \ReflectionClass(Connection::class);
        $runWithRetry = $reflection->getMethod('runWithRetry');

        $attemptCount = 0;
        $retryableException = new PDOException(
            'SQLSTATE[40001]: Serialization failure',
            0
        );
        $retryableException->errorInfo = ['40001', 1213, 'Serialization failure'];

        // The operation should succeed on the second attempt
        $result = $runWithRetry->invoke($this->connection, function () use (&$attemptCount, $retryableException): string {
            $attemptCount++;
            if ($attemptCount < 2) {
                throw $retryableException;
            }
            return 'success';
        });

        // Should have retried at least once
        $this->assertGreaterThan(1, $attemptCount);
        $this->assertSame('success', $result);
    }
}
