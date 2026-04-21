<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Tests\TestCase;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class ConnectionRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Connection::reset();
    }

    protected function tearDown(): void
    {
        Connection::reset();
        parent::tearDown();
    }

    private function freshConnection(array $overrides = []): Connection
    {
        return Connection::createFresh(array_merge(
            [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'retry_attempts' => 3,
                'retry_base_delay_ms' => 0,
            ],
            $overrides,
        ));
    }

    private function shouldRetry(Connection $conn, PDOException $e): bool
    {
        $method = new ReflectionMethod(Connection::class, 'shouldRetry');
        return (bool) $method->invoke($conn, $e);
    }

    private function runWithRetry(Connection $conn, \Closure $op): mixed
    {
        $method = new ReflectionMethod(Connection::class, 'runWithRetry');
        return $method->invoke($conn, $op);
    }

    private function makePdoException(string $sqlState, int $driverCode): PDOException
    {
        $e = new PDOException("simulated", 0);
        $e->errorInfo = [$sqlState, $driverCode, 'simulated'];
        (new ReflectionProperty(PDOException::class, 'code'))->setValue($e, $sqlState);
        return $e;
    }

    public static function retryableErrors(): array
    {
        return [
            'PG deadlock 40P01'     => ['40P01', 0],
            'PG serialization 40001'=> ['40001', 0],
            'MySQL deadlock 1213'   => ['HY000', 1213],
            'MySQL lock wait 1205'  => ['HY000', 1205],
            'MySQL gone 2006'       => ['HY000', 2006],
            'MySQL lost 2013'       => ['HY000', 2013],
            'SQLite busy 5'         => ['HY000', 5],
        ];
    }

    #[Test]
    #[DataProvider('retryableErrors')]
    public function identifiesRetryableErrors(string $sqlState, int $driverCode): void
    {
        $conn = $this->freshConnection();
        $this->assertTrue(
            $this->shouldRetry($conn, $this->makePdoException($sqlState, $driverCode))
        );
    }

    public static function nonRetryableErrors(): array
    {
        return [
            'syntax error 42000'    => ['42000', 1064],
            'unique violation 23000'=> ['23000', 1062],
            'missing table 42S02'   => ['42S02', 1146],
        ];
    }

    #[Test]
    #[DataProvider('nonRetryableErrors')]
    public function doesNotRetryUnrelatedErrors(string $sqlState, int $driverCode): void
    {
        $conn = $this->freshConnection();
        $this->assertFalse(
            $this->shouldRetry($conn, $this->makePdoException($sqlState, $driverCode))
        );
    }

    #[Test]
    public function retriesUntilSuccess(): void
    {
        $conn = $this->freshConnection();
        $exception = $this->makePdoException('HY000', 1213);
        $attempts = 0;

        $result = $this->runWithRetry($conn, function () use (&$attempts, $exception) {
            $attempts++;
            if ($attempts < 3) {
                throw $exception;
            }
            return 'ok';
        });

        $this->assertSame(3, $attempts);
        $this->assertSame('ok', $result);
    }

    #[Test]
    public function givesUpAfterConfiguredAttempts(): void
    {
        $conn = $this->freshConnection(['retry_attempts' => 2]);
        $exception = $this->makePdoException('HY000', 1213);
        $attempts = 0;

        try {
            $this->runWithRetry($conn, function () use (&$attempts, $exception) {
                $attempts++;
                throw $exception;
            });
            $this->fail('expected PDOException');
        } catch (PDOException) {
            $this->assertSame(2, $attempts);
        }
    }

    #[Test]
    public function doesNotRetryInsideTransaction(): void
    {
        $conn = $this->freshConnection();
        $conn->beginTransaction();
        $attempts = 0;
        $exception = $this->makePdoException('HY000', 1213);

        try {
            $this->runWithRetry($conn, function () use (&$attempts, $exception) {
                $attempts++;
                throw $exception;
            });
            $this->fail('expected PDOException');
        } catch (PDOException) {
            $this->assertSame(1, $attempts, 'retry must be suppressed inside a transaction');
        } finally {
            $conn->rollBack();
        }
    }

    #[Test]
    public function nonRetryableErrorBubblesImmediately(): void
    {
        $conn = $this->freshConnection();
        $attempts = 0;
        $exception = $this->makePdoException('42000', 1064);

        try {
            $this->runWithRetry($conn, function () use (&$attempts, $exception) {
                $attempts++;
                throw $exception;
            });
            $this->fail('expected PDOException');
        } catch (PDOException) {
            $this->assertSame(1, $attempts);
        }
    }
}
