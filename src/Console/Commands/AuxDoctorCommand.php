<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Aux\AuxDoctor;
use Fw\Aux\AuxStats;
use Fw\Aux\FeatureIndex;
use Fw\Aux\ToolRegistry;
use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Core\Application as HttpApplication;

final class AuxDoctorCommand extends Command
{
    private const array LEVEL_LABELS = [
        0 => 'Raw MCP wrapper',
        1 => 'Workflow tools',
        2 => 'Feature index + notifications',
        3 => 'Budget-aware + adaptive',
    ];

    protected string $name = 'aux:doctor';

    protected string $description = 'Audit AUX surface against the maturity model, print score + next steps';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function handle(): int
    {
        $container = HttpApplication::getInstance()->getContainer();

        /** @var ToolRegistry $registry */
        $registry = $container->get(ToolRegistry::class);

        /** @var FeatureIndex $features */
        $features = $container->get(FeatureIndex::class);

        /** @var AuxStats $stats */
        $stats = $container->get(AuxStats::class);

        $channelsConfig = $this->app->config('aux.notification_channels', []);
        $notificationChannels = is_array($channelsConfig) ? array_keys($channelsConfig) : [];

        $doctor = new AuxDoctor(
            $registry,
            $features,
            $stats,
            $notificationChannels,
        );

        $report = $doctor->audit();

        $this->newLine();
        $this->info('AUX Maturity Report');
        $this->newLine();

        $this->table(
            ['Level', 'Label', 'Status'],
            array_map(
                static fn(int $lvl): array => [
                    (string) $lvl,
                    self::LEVEL_LABELS[$lvl],
                    $lvl <= $report->level ? '✓' : '✗',
                ],
                [0, 1, 2, 3],
            ),
        );

        $this->newLine();
        $this->line('Checks:');
        foreach ($report->checks as $check) {
            $marker = $check['passed'] ? '✓' : '✗';
            $this->line(sprintf('  [L%d] %s %s', $check['level'], $marker, $check['name']));
            if (!$check['passed']) {
                $this->line('       → ' . $check['hint']);
            }
        }

        $this->newLine();
        $this->line(sprintf(
            'Maturity level: %d/3 (%d/%d checks passed)',
            $report->level,
            $report->score,
            $report->total,
        ));
        $this->newLine();

        return 0;
    }
}
