<?php

declare(strict_types=1);

namespace Fw\Aux;

final readonly class AuxDoctor
{
    private const array BUILTIN_TOOL_NAMES = ['list_features', 'list_routes'];

    /**
     * @param list<string> $notificationChannels
     */
    public function __construct(
        private ToolRegistry $tools,
        private FeatureIndex $features,
        private AuxStats $stats,
        private array $notificationChannels,
    ) {}

    public function audit(): AuxDoctorReport
    {
        $userTools = array_filter(
            $this->tools->all(),
            static fn(Tool $t): bool => !in_array($t->name, self::BUILTIN_TOOL_NAMES, true),
        );

        $budgetTools = array_filter(
            $userTools,
            static fn(Tool $t): bool => $t->budget !== [],
        );

        $checks = [
            [
                'level' => 1,
                'name' => 'User-defined workflow tools registered',
                'passed' => count($userTools) > 0,
                'hint' => 'Run: php fw make:tool <name> — expose at least one workflow tool.',
            ],
            [
                'level' => 2,
                'name' => 'Feature index populated',
                'passed' => count($this->features->features) > 0,
                'hint' => 'Declare features in App\\Providers\\FeatureIndexProvider so agents can discover navigable URLs.',
            ],
            [
                'level' => 2,
                'name' => 'Notification channels configured',
                'passed' => count($this->notificationChannels) > 0,
                'hint' => 'Enable channels in config/aux.php notification_channels (log/mail/webhook) to give agents a back-channel.',
            ],
            [
                'level' => 3,
                'name' => 'Tools declare budget hints',
                'passed' => count($budgetTools) > 0,
                'hint' => 'Add budget: [estimated_duration_ms => ..., estimated_tokens => ...] to at least one Tool so agents can plan.',
            ],
            [
                'level' => 3,
                'name' => 'Execution stats captured',
                'passed' => count($this->stats->snapshot()) > 0,
                'hint' => 'Invoke a tool via php fw aux:call to populate storage/cache/aux-stats.json.',
            ],
        ];

        return new AuxDoctorReport($checks);
    }
}
