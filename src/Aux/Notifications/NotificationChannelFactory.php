<?php

declare(strict_types=1);

namespace Fw\Aux\Notifications;

use Closure;
use Fw\Events\EventDispatcher;
use Fw\Log\Logger;
use InvalidArgumentException;

final class NotificationChannelFactory
{
    /**
     * @param Closure(): Logger $loggerResolver
     */
    public function __construct(
        private readonly Closure $loggerResolver,
        private readonly ?EventDispatcher $events = null,
    ) {}

    /**
     * @param array<string, array<string, mixed>> $config keyed by channel name
     */
    public function build(array $config): NotificationChannelRegistry
    {
        $registry = new NotificationChannelRegistry($this->events);

        foreach ($config as $name => $spec) {
            $registry->register((string) $name, $this->makeChannel((array) $spec));
        }

        return $registry;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function makeChannel(array $spec): NotificationChannel
    {
        $driver = (string) ($spec['driver'] ?? '');

        return match ($driver) {
            'log' => new LogChannel(($this->loggerResolver)()),
            'mail' => new MailChannel(
                fromAddress: $this->requireString($spec, 'from'),
            ),
            'webhook' => new WebhookChannel(
                url: $this->requireString($spec, 'url'),
                timeout: (int) ($spec['timeout'] ?? 5),
            ),
            default => throw new InvalidArgumentException(
                "Unknown notification channel driver: '{$driver}'"
            ),
        };
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function requireString(array $spec, string $key): string
    {
        if (!isset($spec[$key]) || !is_string($spec[$key]) || $spec[$key] === '') {
            throw new InvalidArgumentException("Notification channel config missing required '{$key}'.");
        }
        return $spec[$key];
    }
}
