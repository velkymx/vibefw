<?php

declare(strict_types=1);

namespace Fw\Aux\Notifications;

use Closure;
use RuntimeException;

final class WebhookChannel implements NotificationChannel
{
    /**
     * @param Closure(string $url, string $body, list<string> $headers): int|null $httpCaller
     */
    public function __construct(
        private readonly string $url,
        private readonly int $timeout = 5,
        private readonly ?Closure $httpCaller = null,
    ) {}

    public function deliver(AgentNotification $notification): void
    {
        $body = json_encode($notification->toArray(), JSON_THROW_ON_ERROR);
        $headers = ['Content-Type: application/json', 'User-Agent: VibeFw-AUX/1.0'];

        $status = $this->httpCaller !== null
            ? ($this->httpCaller)($this->url, $body, $headers)
            : $this->postViaStreams($this->url, $body, $headers);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("WebhookChannel non-2xx response from {$this->url}: {$status}");
        }
    }

    /**
     * @param list<string> $headers
     */
    private function postViaStreams(string $url, string $body, array $headers): int
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return 0;
        }

        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                return (int) $m[1];
            }
        }
        return 0;
    }
}
