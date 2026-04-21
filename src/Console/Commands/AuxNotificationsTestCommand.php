<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Aux\Notifications\AgentNotification;
use Fw\Aux\Notifications\NotificationChannelRegistry;
use Fw\Aux\Notifications\Severity;
use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Core\Application as HttpApplication;
use Throwable;

final class AuxNotificationsTestCommand extends Command
{
    protected string $name = 'aux:notifications:test';

    protected string $description = 'Send a dummy AgentNotification through a channel';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addArgument('channel', 'Channel name (as registered in aux.notification_channels)', true);
        $this->addOption('to', 'Recipient override', 'ops@example.com');
        $this->addOption('severity', 'Severity: info, warn, error', 'info');
    }

    public function handle(): int
    {
        $channel = (string) $this->argument('channel');
        $to = (string) ($this->option('to') ?? 'ops@example.com');
        $severityInput = (string) ($this->option('severity') ?? 'info');

        $severity = Severity::tryFrom($severityInput);
        if ($severity === null) {
            $this->error("Invalid severity '{$severityInput}'. Use info, warn, or error.");
            return 1;
        }

        $registry = HttpApplication::getInstance()
            ->getContainer()
            ->get(NotificationChannelRegistry::class);

        if (!$registry->has($channel)) {
            $this->error("Channel '{$channel}' is not registered. Available: " . implode(', ', $registry->names()));
            return 1;
        }

        $notification = new AgentNotification(
            recipient: $to,
            subject: 'AUX notification test',
            body: "Test delivery via '{$channel}' channel at " . date('c') . '.',
            severity: $severity,
            metadata: ['source' => 'aux:notifications:test'],
        );

        try {
            $registry->deliver($channel, $notification);
        } catch (Throwable $e) {
            $this->error("Delivery failed: {$e->getMessage()}");
            return 1;
        }

        $this->success("Delivered test notification through '{$channel}'.");
        return 0;
    }
}
