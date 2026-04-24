<?php

declare(strict_types=1);

namespace Fw\Aux;

final class AuxStats
{
    /** @var array<string, array{count: int, avg_duration_ms: float, error_count: int}> */
    private array $stats;

    public function __construct(
        private readonly string $path,
    ) {
        $this->stats = $this->load();
    }

    public function record(string $tool, float $durationMs, bool $isError): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }

        // Take an exclusive lock around the full read-modify-write so
        // two workers can't each compute a new entry from their own
        // stale in-memory snapshot and stomp one another on write.
        // 'c+' creates the file if missing, doesn't truncate.
        $fp = @fopen($this->path, 'c+');
        if ($fp === false || !@flock($fp, LOCK_EX)) {
            if ($fp !== false) {
                fclose($fp);
            }
            // Best-effort fallback when the filesystem refuses a lock
            // (some network filesystems / restricted contexts). Still
            // writes via LOCK_EX in persist() — better than dropping
            // the record entirely.
            $this->applyRecord($tool, $durationMs, $isError);
            $this->persist();
            return;
        }

        try {
            $this->stats = $this->load();
            $this->applyRecord($tool, $durationMs, $isError);

            $json = json_encode(
                $this->stats,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );

            rewind($fp);
            ftruncate($fp, 0);
            if ($json !== false) {
                fwrite($fp, $json);
            }
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * @return array<string, array{count: int, avg_duration_ms: float, error_count: int}>
     */
    public function snapshot(): array
    {
        return $this->stats;
    }

    private function applyRecord(string $tool, float $durationMs, bool $isError): void
    {
        $entry = $this->stats[$tool] ?? ['count' => 0, 'avg_duration_ms' => 0.0, 'error_count' => 0];

        $newCount = $entry['count'] + 1;
        $newAvg = (($entry['avg_duration_ms'] * $entry['count']) + $durationMs) / $newCount;

        $this->stats[$tool] = [
            'count' => $newCount,
            'avg_duration_ms' => $newAvg,
            'error_count' => $entry['error_count'] + ($isError ? 1 : 0),
        ];
    }

    /**
     * @return array<string, array{count: int, avg_duration_ms: float, error_count: int}>
     */
    private function load(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $tool => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $normalized[$tool] = [
                'count' => (int) ($entry['count'] ?? 0),
                'avg_duration_ms' => (float) ($entry['avg_duration_ms'] ?? 0.0),
                'error_count' => (int) ($entry['error_count'] ?? 0),
            ];
        }

        return $normalized;
    }

    private function persist(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }

        @file_put_contents(
            $this->path,
            json_encode($this->stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
    }
}
