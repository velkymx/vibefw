<?php

declare(strict_types=1);

namespace Fw\Database;

use Closure;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Connection
{
    private static ?Connection $instance = null;

    private static array $config = [];

    /**
     * Shared hex identifier used to namespace savepoint names. Savepoints are
     * scoped per PDO connection, so every Connection instance can safely reuse
     * the same namespace string; regenerating random entropy per instance was
     * redundant. Generated lazily on first construction.
     */
    private static ?string $connectionIdBase = null;

    public private(set) string $driver;

    public private(set) string $connectionId;

    private PDO $pdo;

    private int $transactionLevel = 0;

    private array $queryLog = [];

    private bool $logging = false;

    private int $retryAttempts = 3;

    private int $retryBaseDelayMs = 10;

    /**
     * SQLSTATE / driver error codes that indicate a transient failure which
     * can safely be retried outside of a transaction. Kept small on purpose —
     * anything non-transient (constraint, syntax, auth) must bubble.
     */
    private const array RETRYABLE_SQLSTATES = [
        '40001', // serialization failure (PG)
        '40P01', // deadlock_detected (PG)
    ];

    private const array RETRYABLE_DRIVER_CODES = [
        1213,    // MySQL deadlock
        1205,    // MySQL lock wait timeout
        2006,    // MySQL server has gone away
        2013,    // MySQL lost connection during query
        5,       // SQLITE_BUSY
        6,       // SQLITE_LOCKED
    ];

    /**
     * Optional callback for recording queries (breaks circular dependency with Core).
     * @var callable|null
     */
    private $queryRecorder = null;

    public function __construct(array $config)
    {
        $this->driver = $config['driver'] ?? 'mysql';
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? ($this->driver === 'pgsql' ? 5432 : 3306);
        $database = $config['database'] ?? '';
        $username = $config['username'] ?? 'root';
        $password = $config['password'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';

        $dsn = match ($this->driver) {
            'mysql' => "mysql:host=$host;port=$port;dbname=$database;charset=$charset",
            'pgsql' => "pgsql:host=$host;port=$port;dbname=$database",
            'sqlite' => "sqlite:$database",
            default => throw new InvalidArgumentException("Unsupported driver: {$this->driver}"),
        };

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        if ($config['persistent'] ?? false) {
            $options[PDO::ATTR_PERSISTENT] = true;
        }

        if ($this->driver === 'mysql') {
            $initCommandAttr = defined('Pdo\Mysql::ATTR_INIT_COMMAND')
                ? \Pdo\Mysql::ATTR_INIT_COMMAND
                : (defined('PDO::MYSQL_ATTR_INIT_COMMAND') ? PDO::MYSQL_ATTR_INIT_COMMAND : 1002);
            $options[$initCommandAttr] = "SET NAMES $charset COLLATE {$charset}_unicode_ci";
        }

        $this->pdo = match ($this->driver) {
            'sqlite' => new PDO($dsn, options: $options),
            default => new PDO($dsn, $username, $password, $options),
        };

        if ($this->driver === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        }

        $this->logging = $config['logging'] ?? false;
        $this->retryAttempts = max(1, (int) ($config['retry_attempts'] ?? 3));
        $this->retryBaseDelayMs = max(0, (int) ($config['retry_base_delay_ms'] ?? 10));
        $this->connectionId = self::$connectionIdBase ??= bin2hex(random_bytes(8));
    }

    public static function getInstance(?array $config = null): self
    {
        if ($config !== null) {
            if (self::$instance !== null && !self::configsMatch(self::$config, $config)) {
                throw new LogicException('Connection config mismatch');
            }
            self::$config = $config;
        }
        if (self::$instance === null && self::$config === []) {
            throw new LogicException('Connection not configured');
        }
        return self::$instance ??= new self(self::$config);
    }

    public static function createFresh(?array $config = null): self
    {
        return new self($config ?? self::$config);
    }

    /**
     * Compare two config arrays independent of key insertion order.
     */
    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function configsMatch(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);
        return $a === $b;
    }

    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    public static function reset(): void
    {
        if (self::$instance !== null) {
            while (self::$instance->transactionLevel > 0) {
                self::$instance->rollBack();
            }
        }
        self::$instance = null;
    }

    /**
     * Set a callback to record queries (e.g. for QueryWatcher).
     */
    public function setQueryRecorder(callable $recorder): void
    {
        $this->queryRecorder = $recorder;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        return $this->runWithRetry(function () use ($sql, $params): PDOStatement {
            $start = microtime(true);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $elapsed = microtime(true) - $start;
            $elapsedMs = $elapsed * 1000;

            if ($this->queryRecorder !== null) {
                ($this->queryRecorder)($sql, $params, $elapsedMs);
            }

            if ($this->logging) {
                $this->queryLog[] = [
                    'sql' => $sql,
                    'params' => $params,
                    'time' => $elapsed,
                ];
            }

            return $stmt;
        });
    }

    /**
     * Execute $op with bounded retries for transient failures. Skips retry
     * inside an active transaction — partial work would replay against a
     * state the caller cannot reason about, so the original exception must
     * surface so the caller can rollback and decide.
     */
    private function runWithRetry(Closure $op): mixed
    {
        if ($this->inTransaction()) {
            return $op();
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $op();
            } catch (PDOException $e) {
                if ($attempt >= $this->retryAttempts || !$this->shouldRetry($e)) {
                    throw $e;
                }
                $this->sleepBeforeRetry($attempt);
            }
        }
    }

    private function shouldRetry(PDOException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        if (in_array($sqlState, self::RETRYABLE_SQLSTATES, true)) {
            return true;
        }

        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        return in_array($driverCode, self::RETRYABLE_DRIVER_CODES, true);
    }

    private function sleepBeforeRetry(int $attempt): void
    {
        if ($this->retryBaseDelayMs === 0) {
            return;
        }
        // Exponential backoff with small jitter so contending workers don't
        // resynchronize on every deadlock cycle.
        $backoff = $this->retryBaseDelayMs * (2 ** ($attempt - 1));
        $jitter = random_int(0, $this->retryBaseDelayMs);
        usleep(($backoff + $jitter) * 1000);
    }

    public function select(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function selectOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }

    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map(fn ($c) => $this->quoteIdentifier($c), $columns)),
            implode(', ', $placeholders)
        );
        $this->query($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        if (empty($data)) {
            throw new \LogicException('update() requires at least one data column to set.');
        }

        if (empty($where)) {
            throw new \LogicException(
                'update() requires at least one WHERE condition to prevent accidental mass updates. '
                . 'Pass [\'1\' => \'1\'] for an intentional bulk update.'
            );
        }

        $set = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set[] = $this->quoteIdentifier($column) . ' = ?';
            $params[] = $value;
        }
        $whereParts = [];
        foreach ($where as $column => $value) {
            $whereParts[] = $this->quoteIdentifier($column) . ' = ?';
            $params[] = $value;
        }
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(', ', $set),
            implode(' AND ', $whereParts)
        );
        return $this->query($sql, $params)->rowCount();
    }

    public function delete(string $table, array $where): int
    {
        if (empty($where)) {
            throw new \LogicException(
                'delete() requires at least one WHERE condition to prevent accidental mass deletes. '
                . 'Pass [\'1\' => \'1\'] for an intentional bulk delete.'
            );
        }

        $whereParts = [];
        $params = [];
        foreach ($where as $column => $value) {
            $whereParts[] = $this->quoteIdentifier($column) . ' = ?';
            $params[] = $value;
        }
        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(' AND ', $whereParts)
        );
        return $this->query($sql, $params)->rowCount();
    }

    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            try {
                $this->rollBack();
            } catch (Throwable $rollbackException) {
                // Log the rollback failure but always re-throw the original exception
                // so the caller knows what actually went wrong.
                error_log('Transaction rollback failed: ' . $rollbackException->getMessage());
            }
            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        if ($this->transactionLevel === 0) {
            $this->pdo->beginTransaction();
        } else {
            $savepointName = $this->getSavepointName($this->transactionLevel);
            $this->pdo->exec("SAVEPOINT {$savepointName}");
        }
        $this->transactionLevel++;
    }

    public function commit(): void
    {
        if ($this->transactionLevel <= 0) {
            throw new LogicException('Cannot commit: no transaction is active.');
        }

        $this->transactionLevel--;
        if ($this->transactionLevel === 0) {
            $this->pdo->commit();
        } else {
            $savepointName = $this->getSavepointName($this->transactionLevel);
            $this->pdo->exec("RELEASE SAVEPOINT {$savepointName}");
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionLevel <= 0) {
            throw new LogicException('Cannot rollback: no transaction is active.');
        }

        $this->transactionLevel--;
        if ($this->transactionLevel === 0) {
            $this->pdo->rollBack();
        } else {
            $savepointName = $this->getSavepointName($this->transactionLevel);
            $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepointName}");
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    public function lastInsertId(): string
    {
        return (string) $this->pdo->lastInsertId();
    }

    public function quoteIdentifier(string $identifier): string
    {
        return match ($this->driver) {
            'mysql' => '`' . str_replace('`', '``', $identifier) . '`',
            'sqlite', 'pgsql' => '"' . str_replace('"', '""', $identifier) . '"',
            // Fail loudly for unknown drivers rather than returning a bare identifier,
            // which would silently produce injection-vulnerable SQL.
            default => throw new RuntimeException(
                "Cannot safely quote identifier for unsupported database driver '{$this->driver}'."
            ),
        };
    }

    public function table(string $name): QueryBuilder
    {
        return new QueryBuilder($this)->table($name);
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    private function getSavepointName(int $level): string
    {
        $safeConnectionId = preg_replace('/[^a-f0-9]/i', '', $this->connectionId);
        $safeLevel = max(0, $level);
        return "sp_{$safeConnectionId}_{$safeLevel}";
    }
}
