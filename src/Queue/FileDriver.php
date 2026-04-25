<?php

declare(strict_types=1);

namespace Fw\Queue;

use __PHP_Incomplete_Class;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class FileDriver implements DriverInterface
{
    /**
     * Lock file TTL in seconds. Lock files older than this are considered stale.
     */
    private const int LOCK_FILE_TTL = 300;

    /**
     * Maximum job payload size in bytes (1MB default).
     * Prevents disk exhaustion attacks via oversized job payloads.
     */
    private const int MAX_PAYLOAD_SIZE = 1024 * 1024;

    private string $path;

    /**
     * Secret key for HMAC signing of serialized job payloads.
     * Prevents RCE via deserialization of tampered payloads.
     */
    private string $secretKey;

    /**
     * Allowed classes for unserialize. Fail-closed by default — pop() refuses
     * to deserialize until an explicit list of JobInterface implementations is
     * supplied via allowClasses(). HMAC signing prevents external tampering,
     * but if the queue dir or HMAC key ever leaks, a wide-open allowlist would
     * turn any class with a __destruct/__wakeup gadget into an RCE primitive.
     *
     * @var list<class-string<JobInterface>>
     */
    private array $allowedClasses = [];

    public function __construct(string $path, ?string $secretKey = null)
    {
        $this->path = rtrim($path, '/');

        // Use provided key or generate one and store it
        $this->secretKey = $secretKey ?? $this->getOrCreateSecretKey();

        if (!is_dir($this->path)) {
            mkdir($this->path, 0o750, true);
        }
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
        $queue = $job->getQueue();
        $jobId = $this->generateId($queue);
        $availableAt = time() + $delay;

        // Serialize and validate payload size
        $serialized = serialize($job);
        if (strlen($serialized) > self::MAX_PAYLOAD_SIZE) {
            throw new RuntimeException(
                'Job payload too large: ' . strlen($serialized) . ' bytes exceeds maximum of ' . self::MAX_PAYLOAD_SIZE
            );
        }

        // Sign the serialized job to prevent RCE via payload tampering
        $signedJob = $this->signPayload($serialized);

        $payload = [
            'id' => $jobId,
            'queue' => $queue,
            'job' => $signedJob,
            'attempts' => 0,
            'available_at' => $availableAt,
            'created_at' => time(),
            'reserved_at' => null,
        ];

        $this->ensureQueueDirectory($queue);
        $file = $this->getJobPath($queue, $jobId);

        file_put_contents($file, json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX);

        return $jobId;
    }

    public function pop(string $queue = 'default'): ?array
    {
        $this->ensureQueueDirectory($queue);
        $dir = $this->getQueuePath($queue);
        $now = time();

        // Clean up stale lock files before processing
        $this->cleanupStaleLocks($dir, $now);

        $files = glob($dir . '/*.json');

        if (empty($files)) {
            return null;
        }

        // Sort by filename (which includes timestamp)
        sort($files);

        foreach ($files as $file) {
            $lockFile = $file . '.lock';

            // Try to acquire lock. fopen() returning false (unwritable
            // dir, ENFILE/EMFILE, disk full) MUST NOT flow into flock(),
            // which would TypeError on PHP 8+ and kill the worker. Skip
            // the job; another worker (or a later poll) can retry.
            $lock = @fopen($lockFile, 'c');
            if ($lock === false) {
                error_log("Queue: skipping job — cannot open lock file: {$lockFile}");
                continue;
            }
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                fclose($lock);
                continue;
            }

            if (!file_exists($file)) {
                flock($lock, LOCK_UN);
                fclose($lock);
                @unlink($lockFile);
                continue;
            }

            // file_get_contents() returning false is "unreadable", NOT
            // "corrupt". Permission flips, EIO, EBUSY mid-pop must not
            // be misclassified as a malformed payload — silently
            // unlinking on a transient read failure destroys legitimate
            // work. Release the lock and skip; the file stays on disk
            // for the next pop attempt to read.
            $content = @file_get_contents($file);
            if ($content === false) {
                flock($lock, LOCK_UN);
                fclose($lock);
                error_log("Queue: skipping unreadable job file: {$file}");
                continue;
            }

            try {
                $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                flock($lock, LOCK_UN);
                fclose($lock);
                @unlink($file);
                @unlink($lockFile);
                error_log("Queue: deleted corrupt job file: {$file}");
                continue;
            }

            // Check if job is available
            if ($payload['available_at'] > $now) {
                flock($lock, LOCK_UN);
                fclose($lock);
                continue;
            }

            // Check if already reserved and not timed out (5 min timeout)
            if ($payload['reserved_at'] !== null && ($now - $payload['reserved_at']) < 300) {
                flock($lock, LOCK_UN);
                fclose($lock);
                continue;
            }

            // Reserve the job
            $payload['reserved_at'] = $now;
            $payload['attempts']++;

            file_put_contents($file, json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX);

            flock($lock, LOCK_UN);
            fclose($lock);

            // Verify signature before deserializing (prevents RCE via tampering)
            try {
                $serialized = $this->verifyPayload($payload['job']);
            } catch (RuntimeException $e) {
                // Tampered or corrupted job - delete it
                @unlink($file);
                @unlink($lockFile);
                throw $e;
            }

            if ($this->allowedClasses === []) {
                throw new RuntimeException(
                    'Queue allowed_classes is empty — refusing to unserialize. '
                    . 'Configure queue.allowed_classes (list of JobInterface implementations) '
                    . 'or call FileDriver::allowClasses([...]) before pop().'
                );
            }

            $job = unserialize($serialized, ['allowed_classes' => $this->allowedClasses]);

            if ($job instanceof __PHP_Incomplete_Class) {
                throw new RuntimeException(
                    'Invalid job payload: class not found during deserialization. ' .
                    'Ensure the concrete job class is available to the autoloader.'
                );
            }

            if (!$job instanceof JobInterface) {
                throw new RuntimeException('Invalid job payload: not a JobInterface');
            }
            $job->setJobId($payload['id']);

            for ($i = 0; $i < $payload['attempts']; $i++) {
                $job->incrementAttempts();
            }

            return [
                'id' => $payload['id'],
                'job' => $job,
                'attempts' => $payload['attempts'],
                'file' => $file,
                'lock_file' => $lockFile,
            ];
        }

        return null;
    }

    public function delete(string $jobId): bool
    {
        // Fast path: IDs generated by this driver encode the queue
        // name as a prefix (`{queue}@{ts}_{rand}`), so we can hit
        // one file directly instead of scanning every queue.
        $prefixedQueue = $this->extractQueueFromId($jobId);
        if ($prefixedQueue !== null) {
            $file = $this->getJobPath($prefixedQueue, $jobId);
            if (file_exists($file)) {
                @unlink($file);
                @unlink($file . '.lock');
                return true;
            }
            return false;
        }

        // Legacy fallback for jobs persisted with the old opaque ID
        // format. Safe to remove once no pre-[R46] jobs remain on disk.
        foreach ($this->getAllQueues() as $queue) {
            $file = $this->getJobPath($queue, $jobId);
            $lockFile = $file . '.lock';

            if (file_exists($file)) {
                @unlink($file);
                @unlink($lockFile);
                return true;
            }
        }

        return false;
    }

    public function release(string $jobId, int $delay = 0): bool
    {
        $prefixedQueue = $this->extractQueueFromId($jobId);
        $queues = $prefixedQueue !== null ? [$prefixedQueue] : $this->getAllQueues();

        foreach ($queues as $queue) {
            $file = $this->getJobPath($queue, $jobId);

            if (!file_exists($file)) {
                continue;
            }

            $content = file_get_contents($file);

            try {
                $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                error_log("Queue: corrupt job file during release: {$file}");
                continue;
            }

            $payload['reserved_at'] = null;
            $payload['available_at'] = time() + $delay;

            file_put_contents($file, json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX);

            return true;
        }

        return false;
    }

    public function size(string $queue = 'default'): int
    {
        $this->ensureQueueDirectory($queue);
        $dir = $this->getQueuePath($queue);
        $files = glob($dir . '/*.json');

        return count($files);
    }

    public function clear(string $queue = 'default'): int
    {
        $this->ensureQueueDirectory($queue);
        $dir = $this->getQueuePath($queue);
        $files = glob($dir . '/*.json');
        $count = 0;

        foreach ($files as $file) {
            if (@unlink($file)) {
                @unlink($file . '.lock');
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get or create a persistent secret key for job signing.
     *
     * Uses file locking to prevent race conditions when multiple workers
     * start simultaneously and all try to create/read the key.
     */
    private function getOrCreateSecretKey(): string
    {
        // Ensure directory exists first
        if (!is_dir($this->path)) {
            mkdir($this->path, 0o750, true);
        }

        $keyFile = $this->path . '/.queue_key';
        $lockFile = $this->path . '/.queue_key.lock';

        // Acquire exclusive lock before any file operations
        $lockHandle = fopen($lockFile, 'c');
        if ($lockHandle === false) {
            throw new RuntimeException('Failed to open lock file for queue key');
        }

        try {
            // Block until we get exclusive lock
            if (!flock($lockHandle, LOCK_EX)) {
                throw new RuntimeException('Failed to acquire lock for queue key');
            }

            // Re-check if file exists AFTER acquiring lock (another process may have created it)
            if (file_exists($keyFile)) {
                $key = file_get_contents($keyFile);
                if ($key !== false && strlen($key) >= 32) {
                    return $key;
                }
            }

            // Generate a new key
            $key = bin2hex(random_bytes(32));

            // Order: create empty temp file, tighten perms BEFORE key bytes
            // land, then write + atomic rename. Avoids a process-wide
            // permission-mask side effect that could affect concurrent
            // file creation in other fibers / extensions.
            $tempFile = $keyFile . '.' . bin2hex(random_bytes(8)) . '.tmp';
            if (!touch($tempFile)) {
                throw new RuntimeException('Failed to create queue key temp file');
            }
            if (!chmod($tempFile, 0o600)) {
                @unlink($tempFile);
                throw new RuntimeException('Failed to set permissions on queue key temp file');
            }
            if (file_put_contents($tempFile, $key, LOCK_EX) === false) {
                @unlink($tempFile);
                throw new RuntimeException('Failed to write queue key temp file');
            }
            if (!rename($tempFile, $keyFile)) {
                @unlink($tempFile);
                throw new RuntimeException('Failed to atomically create queue key file');
            }

            // Verify permissions were set correctly
            $perms = fileperms($keyFile) & 0o777;
            if ($perms !== 0o600) {
                error_log(
                    "Warning: Queue secret key file has unexpected permissions {$perms}. Expected 0600."
                );
            }

            return $key;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
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

    /**
     * Generate a jobId that carries its queue name as a prefix so
     * `delete()` and `release()` can resolve the file path in O(1)
     * without scanning every queue directory. Shape:
     *   `{sanitizedQueue}@{unixTimestamp}_{16 hex chars}`
     */
    private function generateId(string $queue): string
    {
        return sprintf(
            '%s@%d_%s',
            $this->sanitizeQueueName($queue),
            time(),
            bin2hex(random_bytes(8))
        );
    }

    /**
     * Extract the queue name encoded into a jobId by `generateId()`.
     * Returns null for legacy opaque IDs (no `@`) or for IDs whose
     * prefix fails the queue-name shape check — those fall back to
     * the O(n) scan path in `delete()` / `release()` and can't be
     * spoofed into traversing arbitrary directories.
     */
    private function extractQueueFromId(string $jobId): ?string
    {
        $at = strpos($jobId, '@');
        if ($at === false || $at === 0) {
            return null;
        }
        $queue = substr($jobId, 0, $at);
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $queue)) {
            return null;
        }
        return $queue;
    }

    private function getQueuePath(string $queue): string
    {
        return $this->path . '/' . $this->sanitizeQueueName($queue);
    }

    private function getJobPath(string $queue, string $jobId): string
    {
        return $this->getQueuePath($queue) . '/' . $jobId . '.json';
    }

    private function ensureQueueDirectory(string $queue): void
    {
        $dir = $this->getQueuePath($queue);

        if (!is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }
    }

    private function sanitizeQueueName(string $queue): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $queue) ?: 'default';

        // Limit queue name length to prevent filesystem issues
        if (strlen($sanitized) > 64) {
            $sanitized = substr($sanitized, 0, 64);
        }

        return $sanitized;
    }

    /**
     * Clean up stale lock files that may have been left by crashed workers.
     *
     * Lock files older than LOCK_FILE_TTL seconds are considered orphaned
     * and are removed to prevent queue deadlock.
     */
    private function cleanupStaleLocks(string $dir, int $now): void
    {
        $lockFiles = glob($dir . '/*.lock');

        if (empty($lockFiles)) {
            return;
        }

        foreach ($lockFiles as $lockFile) {
            $mtime = @filemtime($lockFile);

            if ($mtime === false) {
                continue;
            }

            // If lock file is older than TTL, it's stale
            if (($now - $mtime) > self::LOCK_FILE_TTL) {
                // Try to acquire the lock before deleting (in case it's legitimately held)
                $lock = @fopen($lockFile, 'c');
                if ($lock !== false) {
                    if (flock($lock, LOCK_EX | LOCK_NB)) {
                        // We got the lock, so it was indeed orphaned
                        flock($lock, LOCK_UN);
                        fclose($lock);
                        @unlink($lockFile);
                    } else {
                        // Lock is held by another process, touch it to update mtime
                        fclose($lock);
                        @touch($lockFile);
                    }
                }
            }
        }
    }

    private function getAllQueues(): array
    {
        $queues = [];
        $dirs = glob($this->path . '/*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $queues[] = basename($dir);
        }

        return $queues ?: ['default'];
    }
}
