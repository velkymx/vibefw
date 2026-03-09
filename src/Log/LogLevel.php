<?php

declare(strict_types=1);

namespace Fw\Log;

/**
 * Log levels following PSR-3 conventions.
 */
enum LogLevel: string
{
    /**
     * Create from string (case-insensitive).
     */
    public static function fromString(string $level): self
    {
        $level = strtolower($level);

        return match ($level) {
            'debug' => self::DEBUG,
            'info' => self::INFO,
            'notice' => self::NOTICE,
            'warning', 'warn' => self::WARNING,
            'error' => self::ERROR,
            'critical' => self::CRITICAL,
            'alert' => self::ALERT,
            'emergency' => self::EMERGENCY,
            default => self::DEBUG,
        };
    }

    /**
     * Get the numeric priority (lower = more severe).
     */
    public function priority(): int
    {
        return match ($this) {
            self::EMERGENCY => 0,
            self::ALERT => 1,
            self::CRITICAL => 2,
            self::ERROR => 3,
            self::WARNING => 4,
            self::NOTICE => 5,
            self::INFO => 6,
            self::DEBUG => 7,
        };
    }

    /**
     * Check if this level should be logged given a minimum level.
     */
    public function shouldLog(LogLevel $minimumLevel): bool
    {
        return $this->priority() <= $minimumLevel->priority();
    }
    case DEBUG = 'debug';
    case INFO = 'info';
    case NOTICE = 'notice';
    case WARNING = 'warning';
    case ERROR = 'error';
    case CRITICAL = 'critical';
    case ALERT = 'alert';
    case EMERGENCY = 'emergency';
}
