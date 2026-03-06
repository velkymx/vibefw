<?php

declare(strict_types=1);

namespace Fw\Database;

use Fw\Log\Logger;
use Fw\Support\ResettableInterface;

/**
 * Query Watcher - detects N+1 queries and slow queries.
 */
final class QueryWatcher implements ResettableInterface
{
    private bool $enabled = false;
    private int $slowQueryThreshold = 100;
    private int $nPlusOneThreshold = 3;

    /** @var array<int, array{sql: string, time: float, bindings: array, trace: string}> */
    private array $queries = [];

    /** @var array<string, int> */
    private array $patternCounts = [];

    /** @var array<string, array{count: int, example: string, trace: string}> */
    private array $nPlusOneQueries = [];

    /** @var array<int, array{sql: string, time: float, trace: string}> */
    private array $slowQueries = [];

    public function enable(): void
    {
        $this->enabled = true;
        $this->reset();
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function reset(): void
    {
        $this->queries = [];
        $this->patternCounts = [];
        $this->nPlusOneQueries = [];
        $this->slowQueries = [];
    }

    public function recordQuery(string $sql, array $bindings, float $timeMs): void
    {
        if (!$this->enabled) {
            return;
        }

        $trace = $this->getSimplifiedTrace();

        $this->queries[] = [
            'sql' => $sql,
            'time' => $timeMs,
            'bindings' => $bindings,
            'trace' => $trace,
        ];

        if ($timeMs >= $this->slowQueryThreshold) {
            $this->slowQueries[] = [
                'sql' => $sql,
                'time' => $timeMs,
                'trace' => $trace,
            ];
        }

        $pattern = $this->normalizeQueryPattern($sql);
        $this->patternCounts[$pattern] = ($this->patternCounts[$pattern] ?? 0) + 1;

        if ($this->patternCounts[$pattern] === $this->nPlusOneThreshold) {
            $this->nPlusOneQueries[$pattern] = [
                'count' => $this->patternCounts[$pattern],
                'example' => $sql,
                'trace' => $trace,
            ];
        } elseif (isset($this->nPlusOneQueries[$pattern])) {
            $this->nPlusOneQueries[$pattern]['count'] = $this->patternCounts[$pattern];
        }
    }

    private function normalizeQueryPattern(string $sql): string
    {
        $pattern = preg_replace('/\b\d+\b/', '?', $sql);
        $pattern = preg_replace("/'[^']*'/", '?', $pattern);
        $pattern = preg_replace('/"[^"]*"/', '?', $pattern);
        $pattern = preg_replace('/IN\s*\([^)]+\)/i', 'IN (?)', $pattern);
        return preg_replace('/\s+/', ' ', trim($pattern));
    }

    private function getSimplifiedTrace(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $relevant = [];
        foreach ($trace as $frame) {
            if (isset($frame['file'])) {
                if (str_contains($frame['file'], '/src/Database/') || str_contains($frame['file'], '/src/Model/')) {
                    continue;
                }
            }
            $location = ($frame['file'] ?? 'unknown') . ':' . ($frame['line'] ?? 0);
            $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? 'unknown');
            $relevant[] = "{$call} ({$location})";
            if (count($relevant) >= 3) break;
        }
        return implode(' <- ', $relevant);
    }

    public function getReport(): array
    {
        return [
            'total_queries' => count($this->queries),
            'total_time' => round(array_sum(array_column($this->queries, 'time')), 2),
            'slow_queries' => $this->slowQueries,
            'n_plus_one' => $this->nPlusOneQueries,
        ];
    }

    public function report(?Logger $logger = null): void
    {
        if (!$this->enabled) return;
        $report = $this->getReport();
        foreach ($report['slow_queries'] as $query) {
            $message = sprintf('SLOW QUERY (%.2fms): %s [%s]', $query['time'], $query['sql'], $query['trace']);
            if ($logger) $logger->warning($message); else error_log($message);
        }
        foreach ($report['n_plus_one'] as $pattern => $info) {
            $message = sprintf('N+1 QUERY DETECTED (%d times): %s [%s]', $info['count'], $info['example'], $info['trace']);
            if ($logger) $logger->warning($message); else error_log($message);
        }
    }
}
