<?php

declare(strict_types=1);

namespace Fw\Async;

use Fw\Database\Connection;
use PDOStatement;
use Throwable;

/**
 * Deferred database wrapper using Fibers.
 *
 * ⚠️  THIS CLASS IS NOT PARALLEL / NOT CONCURRENT.
 *
 * The name is retained for backwards compatibility, but the behaviour
 * is better described as "deferred sync": every call is enqueued on
 * the event loop via `defer()` and then executed sequentially against
 * a blocking PDO connection. Each query blocks the fiber until the
 * database responds — there is no pipelining, no overlap, and no
 * parallelism with other fibers waiting on other queries.
 *
 * Given ten `fetchAll` calls, expect wall-clock time ≈ Σ query
 * durations, NOT max(query durations). If you need genuine
 * parallelism, use a truly non-blocking driver such as amphp/mysql
 * or reactphp/mysql with their native event loops.
 *
 * This wrapper is useful for:
 *  - Consistent Deferred API across sync/async code paths.
 *  - Deferring execution to the next event loop tick (e.g. to let
 *    a response flush headers before a query runs).
 *  - Future migration to truly async drivers without touching
 *    calling code.
 *
 * Do NOT use this class when your goal is to run queries in
 * parallel — it will silently serialise them and your latency
 * budget will double.
 *
 * @example
 * $db = new AsyncDatabase($connection);
 * $users = $db->fetchAll('SELECT * FROM users')->await();
 */
final class AsyncDatabase
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Execute async query - returns Deferred that resolves to PDOStatement.
     *
     * @param array<int|string, mixed> $params
     */
    public function query(string $sql, array $params = []): Deferred
    {
        $deferred = new Deferred();

        // For SQLite (sync), resolve in next tick via defer
        // For MySQL/PostgreSQL with async drivers, this would use non-blocking I/O
        EventLoop::getInstance()->defer(function () use ($deferred, $sql, $params): void {
            try {
                $result = $this->connection->query($sql, $params);
                $deferred->resolve($result);
            } catch (Throwable $e) {
                $deferred->reject($e);
            }
        });

        return $deferred;
    }

    /**
     * Fetch all rows async.
     *
     * @param array<int|string, mixed> $params
     * @return Deferred Resolves to array<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): Deferred
    {
        $deferred = new Deferred();

        EventLoop::getInstance()->defer(function () use ($deferred, $sql, $params): void {
            try {
                $result = $this->connection->select($sql, $params);
                $deferred->resolve($result);
            } catch (Throwable $e) {
                $deferred->reject($e);
            }
        });

        return $deferred;
    }

    /**
     * Fetch single row async.
     *
     * @param array<int|string, mixed> $params
     * @return Deferred Resolves to array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): Deferred
    {
        $deferred = new Deferred();

        EventLoop::getInstance()->defer(function () use ($deferred, $sql, $params): void {
            try {
                $result = $this->connection->selectOne($sql, $params);
                $deferred->resolve($result);
            } catch (Throwable $e) {
                $deferred->reject($e);
            }
        });

        return $deferred;
    }

    /**
     * Insert a row async.
     *
     * @param array<string, mixed> $data
     * @return Deferred Resolves to int (last insert ID)
     */
    public function insert(string $table, array $data): Deferred
    {
        $deferred = new Deferred();

        EventLoop::getInstance()->defer(function () use ($deferred, $table, $data): void {
            try {
                $result = $this->connection->insert($table, $data);
                $deferred->resolve($result);
            } catch (Throwable $e) {
                $deferred->reject($e);
            }
        });

        return $deferred;
    }

    /**
     * Update rows async.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     * @return Deferred Resolves to int (affected rows)
     */
    public function update(string $table, array $data, array $where): Deferred
    {
        $deferred = new Deferred();

        EventLoop::getInstance()->defer(function () use ($deferred, $table, $data, $where): void {
            try {
                $result = $this->connection->update($table, $data, $where);
                $deferred->resolve($result);
            } catch (Throwable $e) {
                $deferred->reject($e);
            }
        });

        return $deferred;
    }

    /**
     * Delete rows async.
     *
     * @param array<string, mixed> $where
     * @return Deferred Resolves to int (affected rows)
     */
    public function delete(string $table, array $where): Deferred
    {
        $deferred = new Deferred();

        EventLoop::getInstance()->defer(function () use ($deferred, $table, $where): void {
            try {
                $result = $this->connection->delete($table, $where);
                $deferred->resolve($result);
            } catch (Throwable $e) {
                $deferred->reject($e);
            }
        });

        return $deferred;
    }

    /**
     * Execute a transaction async.
     *
     * @return Deferred Resolves to the callback's return value
     */
    public function transaction(callable $callback): Deferred
    {
        $deferred = new Deferred();

        EventLoop::getInstance()->defer(function () use ($deferred, $callback): void {
            try {
                $result = $this->connection->transaction($callback);
                $deferred->resolve($result);
            } catch (Throwable $e) {
                $deferred->reject($e);
            }
        });

        return $deferred;
    }

    /**
     * Get the underlying synchronous connection.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
