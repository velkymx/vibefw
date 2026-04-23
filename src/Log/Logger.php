<?php

declare(strict_types=1);

namespace Fw\Log;

use Fw\Core\Env;
use Throwable;

/**
 * Simple file-based logger with daily rotation.
 *
 * Supports PSR-3 style log levels and context interpolation.
 * Logs are written to daily rotating files in the configured directory.
 */
final class Logger
{
    private static ?Logger $instance = null;

    private string $path;

    private LogLevel $minimumLevel;

    private string $dateFormat;

    private bool $enabled;

    /**
     * Cap on how many *consecutive* write failures get reported before
     * we start swallowing them. Prevents a permanently-broken log path
     * from spewing an unbounded stream of identical error messages,
     * while still recovering automatically once a single write
     * succeeds (see `$consecutiveWriteFailures` reset below).
     */
    private const int MAX_REPORTED_FAILURES = 3;

    /** Running count of consecutive `file_put_contents` failures. Reset on success. */
    private int $consecutiveWriteFailures = 0;

    /**
     * Pluggable reporter for write failures. Defaults to stderr (or
     * `error_log()` when STDERR isn't defined, e.g. mod_php). Tests
     * swap it to capture reports without polluting the real stderr.
     *
     * @var (callable(string): void)|null
     */
    private $failureReporter = null;

    public function __construct(?string $path = null, ?LogLevel $minimumLevel = null)
    {
        $this->path = $path ?? $this->getDefaultPath();
        $this->minimumLevel = $minimumLevel ?? $this->getDefaultLevel();
        $this->dateFormat = 'Y-m-d H:i:s';
        $this->enabled = Env::bool('LOG_ENABLED', true);

        $this->ensureDirectoryExists();
    }

    /**
     * Get the singleton logger instance.
     *
     * @deprecated Use dependency injection via Container instead.
     *             Register Logger in your service provider:
     *             $container->singleton(Logger::class, fn() => new Logger($path, $level));
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Set the singleton instance (for container integration).
     */
    public static function setInstance(self $logger): void
    {
        self::$instance = $logger;
    }

    /**
     * Create a new logger instance with custom configuration.
     */
    public static function create(string $path, LogLevel $minimumLevel = LogLevel::DEBUG): self
    {
        return new self($path, $minimumLevel);
    }

    /**
     * Reset the singleton instance (useful for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Write a log entry.
     */
    public function log(LogLevel $level, string $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!$level->shouldLog($this->minimumLevel)) {
            return;
        }

        $entry = $this->formatEntry($level, $message, $context);

        $logFile = $this->getLogFile();
        $isNew = !file_exists($logFile);

        $result = @file_put_contents(
            $logFile,
            $entry . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        if ($isNew && $result !== false) {
            @chmod($logFile, 0o640);
        }

        if ($result === false) {
            $this->consecutiveWriteFailures++;
            if ($this->consecutiveWriteFailures <= self::MAX_REPORTED_FAILURES) {
                $errorMessage = "[LOGGER ERROR] Failed to write to log file: {$this->getLogFile()}\n";
                $errorMessage .= "[ORIGINAL MESSAGE] {$entry}\n";
                $this->reportWriteFailure($errorMessage);
            }
        } elseif ($this->consecutiveWriteFailures > 0) {
            // Recovery: one good write resets the counter so reporting resumes
            // for the next outage.
            $this->consecutiveWriteFailures = 0;
        }
    }

    /**
     * Install a custom reporter for write failures. Primarily a testing
     * seam, but also lets containers route failures through a dedicated
     * alerting channel.
     *
     * @param callable(string): void $reporter
     */
    public function setFailureReporter(callable $reporter): void
    {
        $this->failureReporter = $reporter;
    }

    private function reportWriteFailure(string $errorMessage): void
    {
        if ($this->failureReporter !== null) {
            ($this->failureReporter)($errorMessage);
            return;
        }

        if (defined('STDERR')) {
            fwrite(STDERR, $errorMessage);
        } else {
            error_log($errorMessage);
        }
    }

    /**
     * Log a debug message.
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * Log an info message.
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * Log a notice message.
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * Log a warning message.
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * Log an error message.
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * Log a critical message.
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * Log an alert message.
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * Log an emergency message.
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * Get the configured log path.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get the minimum log level.
     */
    public function getMinimumLevel(): LogLevel
    {
        return $this->minimumLevel;
    }

    /**
     * Set the minimum log level.
     */
    public function setMinimumLevel(LogLevel $level): void
    {
        $this->minimumLevel = $level;
    }

    /**
     * Enable logging.
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Disable logging.
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Check if logging is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    private function getDefaultPath(): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        return Env::string('LOG_PATH', $basePath . '/storage/logs');
    }

    private function getDefaultLevel(): LogLevel
    {
        $level = Env::string('LOG_LEVEL', 'debug');
        return LogLevel::fromString($level);
    }

    private function ensureDirectoryExists(): void
    {
        if (is_dir($this->path)) {
            return;
        }

        // Best-effort: suppress warnings so a misconfigured path (existing
        // file, unwritable parent, etc.) doesn't noise up every
        // constructor call. The failure surfaces on the next write
        // attempt where it's reported through `reportWriteFailure`.
        @mkdir($this->path, 0o750, true);
    }

    /**
     * Get the current log file path (daily rotation).
     */
    private function getLogFile(): string
    {
        $filename = Env::string('LOG_FILENAME', 'fw');
        return $this->path . '/' . $filename . '-' . date('Y-m-d') . '.log';
    }

    /**
     * Format a log entry.
     */
    private function formatEntry(LogLevel $level, string $message, array $context): string
    {
        $timestamp = date($this->dateFormat);
        $levelName = strtoupper($level->value);
        $interpolatedMessage = $this->interpolate($message, $context);

        $entry = "[{$timestamp}] {$levelName}: {$interpolatedMessage}";

        // Add exception details if present
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $exception = $context['exception'];
            // Sanitize exception message to prevent log injection
            $sanitizedMessage = $this->sanitizeLogMessage($exception->getMessage());
            $sanitizedFile = $this->sanitizeLogMessage($exception->getFile());

            $entry .= PHP_EOL . "  Exception: " . get_class($exception);
            $entry .= PHP_EOL . "  Message: " . $sanitizedMessage;
            $entry .= PHP_EOL . "  File: " . $sanitizedFile . ':' . $exception->getLine();
            $entry .= PHP_EOL . "  Trace: " . PHP_EOL . $this->formatTrace($exception);
        }

        // Add extra context if present (excluding exception)
        $extra = array_diff_key($context, ['exception' => true]);
        if (!empty($extra)) {
            $entry .= PHP_EOL . "  Context: " . json_encode($extra, JSON_UNESCAPED_SLASHES);
        }

        return $entry;
    }

    /**
     * Format exception trace.
     *
     * Sanitizes the trace to prevent log injection attacks and removes
     * potentially sensitive data like function arguments.
     *
     * WARNING: Log files should NEVER be served directly to browsers.
     * If displaying logs in an admin panel, always HTML-escape the output.
     */
    private function formatTrace(Throwable $exception): string
    {
        $trace = $exception->getTrace();
        $lines = [];

        foreach ($trace as $i => $frame) {
            $file = $frame['file'] ?? '[internal function]';
            $line = $frame['line'] ?? 0;
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $function = $frame['function'] ?? '';

            // Sanitize file paths - remove null bytes and control characters
            $file = preg_replace('/[\x00-\x1f\x7f]/', '', $file) ?? $file;

            // Count arguments but don't log their values (may contain sensitive data)
            $argCount = isset($frame['args']) ? count($frame['args']) : 0;
            $argsPlaceholder = $argCount > 0 ? "...{$argCount} args..." : '';

            $call = $class . $type . $function . '(' . $argsPlaceholder . ')';

            // Sanitize the call string - remove control characters
            $call = preg_replace('/[\x00-\x1f\x7f]/', '', $call) ?? $call;

            $lines[] = "    #{$i} {$file}({$line}): {$call}";
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Sanitize a message string for safe logging.
     *
     * Removes control characters and null bytes that could be used
     * for log injection attacks.
     */
    private function sanitizeLogMessage(string $message): string
    {
        // Remove null bytes and control characters (except newline, tab)
        return preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $message) ?? $message;
    }

    /**
     * Interpolate context values into message placeholders.
     *
     * Placeholders are in the format {key}.
     */
    private function interpolate(string $message, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            if ($key === 'exception') {
                continue;
            }

            if (is_string($value) || is_numeric($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $replacements['{' . $key . '}'] = (string) $value;
            } elseif (is_bool($value)) {
                $replacements['{' . $key . '}'] = $value ? 'true' : 'false';
            } elseif (is_null($value)) {
                $replacements['{' . $key . '}'] = 'null';
            } elseif (is_array($value)) {
                $replacements['{' . $key . '}'] = json_encode($value);
            }
        }

        return strtr($message, $replacements);
    }
}
