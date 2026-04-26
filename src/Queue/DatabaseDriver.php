<?php

declare(strict_types=1);

namespace Fw\Queue;

use __PHP_Incomplete_Class;
use Fw\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

final class DatabaseDriver implements DriverInterface
{
    private Connection $db;

    private string $table;

    private string $quotedTable;

    private string $secretKey;

    /**
     * Allowed classes for unserialize. Fail-closed by default — pop() refuses
     * to deserialize until an explicit list of JobInterface implementations is
     * supplied via allowClasses(). HMAC signing prevents external tampering,
     * but if the database row or HMAC key ever leaks, a wide-open allowlist
     * would turn any class with a __destruct/__wakeup gadget into an RCE
     * primitive.
     *
     * @var list<class-string<JobInterface>>
     */
    private array $allowedClasses = [];

    public function __construct(Connection $db, string $table = 'jobs', ?string $secretKey = null)
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $table)) {
            throw new InvalidArgumentException(
                "Invalid queue table name '{$table}'. Only alphanumerics and underscores are allowed."
            );
        }

        $this->db = $db;
        $this->table = $table;
        $this->quotedTable = $db->quoteIdentifier($table);
        $this->secretKey = $secretKey ?? $this->getSecretKeyFromEnv();
    }

    /**
     * Get the SQL to create the jobs table.
     *
     * @throws InvalidArgumentException If $table contains characters outside [a-zA-Z0-9_]
     */
    public static function createTableSql(string $table = 'jobs'): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $table)) {
            throw new InvalidArgumentException(
                "Invalid queue table name '{$table}'. Only alphanumerics and underscores are allowed."
            );
        }

        return <<<SQL
            CREATE TABLE IF NOT EXISTS `{$table}` (
                id VARCHAR(32) PRIMARY KEY,
                queue VARCHAR(255) NOT NULL DEFAULT 'default',
                payload LONGTEXT NOT NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                available_at INT UNSIGNED NOT NULL,
                reserved_at INT UNSIGNED NULL,
                created_at INT UNSIGNED NOT NULL,
                INDEX idx_queue_available (queue, available_at),
                INDEX idx_reserved (reserved_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
    }

    /**
     * Restrict deserialization to a specific set of JobInterface implementations.
     * Wildcards (`*`) and non-Job classes are rejected so misconfiguration
     * fails loudly at wiring time, not silently at pop().
     *
     * @param list<class-string<JobInterface>> $classes
     * @throws InvalidArgumentException If any entry is a wildcard, an unknown
     *         class, or a class that does not implement JobInterface.
     */
    public function allowClasses(array $classes): self
    {
        foreach ($classes as $class) {
            if (!is_string($class) || $class === '' || $class === '*') {
                throw new InvalidArgumentException(
                    'Queue allowed_classes must be a list of class-strings; '
                    . "wildcards are not permitted (got: " . var_export($class, true) . ')'
                );
            }
            if (!class_exists($class)) {
                throw new InvalidArgumentException(
                    "Queue allowed_classes entry '{$class}' does not exist or is not autoloadable."
                );
            }
            if (!is_subclass_of($class, JobInterface::class)) {
                throw new InvalidArgumentException(
                    "Queue allowed_classes entry '{$class}' must implement " . JobInterface::class . '.'
                );
            }
        }

        $this->allowedClasses = array_values($classes);
        return $this;
    }

    public function push(JobInterface $job): string
    {
        return $this->later($job->getDelay(), $job);
    }

    public function later(int $delay, JobInterface $job): string
    {
        $jobId = bin2hex(random_bytes(16));
        $now = time();

        // Sign the serialized job to prevent RCE via payload tampering
        $signedPayload = $this->signPayload(serialize($job));

        $this->db->insert($this->table, [
            'id' => $jobId,
            'queue' => $job->getQueue(),
            'payload' => $signedPayload,
            'attempts' => 0,
            'available_at' => $now + $delay,
            'reserved_at' => null,
            'created_at' => $now,
        ]);

        return $jobId;
    }

    public function pop(string $queue = 'default'): ?array
    {
        $now = time();

        return $this->db->transaction(function (Connection $db) use ($queue, $now) {
            $row = $db->selectOne(
                "SELECT * FROM {$this->quotedTable}
                 WHERE queue = ?
                   AND available_at <= ?
                   AND (reserved_at IS NULL OR reserved_at < ?)
                 ORDER BY available_at ASC
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED",
                [$queue, $now, $now - 300]
            );

            if ($row === null) {
                return null;
            }

            $db->update(
                $this->table,
                [
                    'reserved_at' => $now,
                    'attempts' => $row['attempts'] + 1,
                ],
                ['id' => $row['id']]
            );

            // Verify signature before deserializing (prevents RCE via tampering)
            try {
                $serialized = $this->verifyPayload($row['payload']);
            } catch (RuntimeException $e) {
                // Tampered or corrupted job - delete it
                $db->delete($this->table, ['id' => $row['id']]);
                throw $e;
            }

            if ($this->allowedClasses === []) {
                throw new RuntimeException(
                    'Queue allowed_classes is empty — refusing to unserialize. '
                    . 'Configure queue.allowed_classes (list of JobInterface implementations) '
                    . 'or call DatabaseDriver::allowClasses([...]) before pop().'
                );
            }

            $job = unserialize($serialized, ['allowed_classes' => $this->allowedClasses]);

            if ($job instanceof __PHP_Incomplete_Class) {
                throw new RuntimeException(
                    'Invalid job payload: class not found during deserialization. ' .
                    'Ensure the concrete job class is available to the autoloader.'
                );
            }

            // Validate the unserialized object implements JobInterface
            if (!$job instanceof JobInterface) {
                throw new RuntimeException('Invalid job payload: not a JobInterface');
            }
            $job->setJobId($row['id']);

            $this->replayAttemptsFromStoredRow($job, (int) $row['attempts']);

            return [
                'id' => $row['id'],
                'job' => $job,
                'attempts' => $row['attempts'] + 1,
            ];
        });
    }

    public function delete(string $jobId): bool
    {
        return $this->db->delete($this->table, ['id' => $jobId]) > 0;
    }

    public function release(string $jobId, int $delay = 0): bool
    {
        return $this->db->update(
            $this->table,
            [
                'reserved_at' => null,
                'available_at' => time() + $delay,
            ],
            ['id' => $jobId]
        ) > 0;
    }

    public function size(string $queue = 'default'): int
    {
        $result = $this->db->selectOne(
            "SELECT COUNT(*) as count FROM {$this->quotedTable} WHERE queue = ?",
            [$queue]
        );

        return (int) ($result['count'] ?? 0);
    }

    public function clear(string $queue = 'default'): int
    {
        return $this->db->query(
            "DELETE FROM {$this->quotedTable} WHERE queue = ?",
            [$queue]
        )->rowCount();
    }

    /**
     * Catch up a freshly-deserialised Job's in-memory `$attempts`
     * counter to the DB value after `pop()`'s UPDATE.
     *
     * Jobs are serialised once at push time with `attempts = 0` and
     * never re-serialised, so every deserialised job starts at 0.
     * Given `$storedAttemptsBeforeUpdate = N` (the value fetched from
     * the SELECT), the UPDATE sets the DB column to `N + 1`. Replay
     * must call `incrementAttempts()` `N + 1` times so the in-memory
     * counter converges to the DB value.
     *
     * Precondition: `$job` is a freshly-deserialised instance with
     * `$attempts = 0`. Calling this method twice on the same instance
     * double-counts.
     */
    private function replayAttemptsFromStoredRow(JobInterface $job, int $storedAttemptsBeforeUpdate): void
    {
        $job->setAttempts($storedAttemptsBeforeUpdate + 1);
    }

    /**
     * Get secret key from environment or generate error.
     */
    private function getSecretKeyFromEnv(): string
    {
        $key = $_ENV['QUEUE_SECRET_KEY'] ?? getenv('QUEUE_SECRET_KEY');

        if ($key === false || $key === '') {
            throw new RuntimeException(
                'QUEUE_SECRET_KEY environment variable must be set for DatabaseDriver. ' .
                'Generate with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        return $key;
    }

    /**
     * Sign a serialized job payload with HMAC.
     */
    private function signPayload(string $serialized): string
    {
        $signature = hash_hmac('sha256', $serialized, $this->secretKey);
        return $signature . '.' . base64_encode($serialized);
    }

    /**
     * Verify and extract a signed job payload.
     *
     * @throws RuntimeException If signature is invalid
     */
    private function verifyPayload(string $signed): string
    {
        $parts = explode('.', $signed, 2);

        if (count($parts) !== 2) {
            throw new RuntimeException('Invalid job payload format');
        }

        [$signature, $encoded] = $parts;
        $serialized = base64_decode($encoded, true);

        if ($serialized === false) {
            throw new RuntimeException('Invalid job payload encoding');
        }

        $expectedSignature = hash_hmac('sha256', $serialized, $this->secretKey);

        if (!hash_equals($expectedSignature, $signature)) {
            throw new RuntimeException('Job payload signature verification failed - possible tampering detected');
        }

        return $serialized;
    }
}
