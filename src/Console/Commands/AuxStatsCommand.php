<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Aux\AuxStats;
use Fw\Aux\Tool;
use Fw\Aux\ToolRegistry;
use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Core\Application as HttpApplication;

final class AuxStatsCommand extends Command
{
    protected string $name = 'aux:stats';

    protected string $description = 'Show per-tool call count, avg duration, error rate, budget vs actual';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function handle(): int
    {
        $container = HttpApplication::getInstance()->getContainer();

        /** @var ToolRegistry $registry */
        $registry = $container->get(ToolRegistry::class);

        /** @var AuxStats $stats */
        $stats = $container->get(AuxStats::class);

        $snapshot = $stats->snapshot();
        $tools = $registry->all();

        if ($tools === []) {
            $this->warning('No tools registered.');
            return 0;
        }

        $rows = [];
        foreach ($tools as $name => $tool) {
            $entry = $snapshot[$name] ?? ['count' => 0, 'avg_duration_ms' => 0.0, 'error_count' => 0];
            $rows[] = $this->buildRow($name, $tool, $entry);
        }

        $this->table(
            ['tool', 'calls', 'estimated ms', 'actual avg ms', 'errors', 'error rate'],
            $rows,
        );

        return 0;
    }

    /**
     * @param array{count: int, avg_duration_ms: float, error_count: int} $entry
     * @return array<int, string>
     */
    private function buildRow(string $name, Tool $tool, array $entry): array
    {
        $estimated = $tool->budget['estimated_duration_ms'] ?? null;
        $estimatedText = $estimated !== null ? (string) $estimated : '—';
        $actualText = $entry['count'] > 0 ? sprintf('%.2f', $entry['avg_duration_ms']) : '—';
        $errorRate = $entry['count'] > 0
            ? sprintf('%.1f%%', ($entry['error_count'] / $entry['count']) * 100)
            : '—';

        return [
            $name,
            (string) $entry['count'],
            $estimatedText,
            $actualText,
            (string) $entry['error_count'],
            $errorRate,
        ];
    }
}
