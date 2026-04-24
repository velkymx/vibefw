<?php

declare(strict_types=1);

namespace Fw\Aux\Notifications;

use Closure;
use RuntimeException;

final class WebhookChannel implements NotificationChannel
{
    /**
     * IP ranges treated as unsafe webhook targets. Matches the SSRF
     * block-list in `Fw\Async\AsyncHttp` so the two sync/async
     * delivery paths can't disagree about what counts as internal.
     */
    private const array BLOCKED_IP_RANGES = [
        '127.0.0.0/8',       // Loopback
        '10.0.0.0/8',        // Private
        '172.16.0.0/12',     // Private
        '192.168.0.0/16',    // Private
        '169.254.0.0/16',    // Link-local / cloud metadata
        '0.0.0.0/8',         // Current network
        '::1/128',           // IPv6 loopback
        'fc00::/7',          // IPv6 private
        'fe80::/10',         // IPv6 link-local
    ];

    /**
     * @param Closure(string $url, string $body, list<string> $headers): int|null $httpCaller
     */
    public function __construct(
        private readonly string $url,
        private readonly int $timeout = 5,
        private readonly ?Closure $httpCaller = null,
        private readonly bool $allowInternalTargets = false,
    ) {
        $this->assertUrlStructurallySafe($this->url);
    }

    public function deliver(AgentNotification $notification): void
    {
        if (!$this->allowInternalTargets) {
            $this->assertHostNotInternal($this->url);
        }

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

        foreach (http_get_last_response_headers() ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    /**
     * Enforce the scheme and host structure at construction so a
     * malformed or hostile URL never reaches `file_get_contents()` —
     * the sync delivery path otherwise honours `file://`, `php://`,
     * `gopher://`, etc. and yields a critical SSRF.
     */
    private function assertUrlStructurallySafe(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false) {
            throw new RuntimeException("WebhookChannel URL is malformed: {$url}");
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new RuntimeException(
                "WebhookChannel URL must use http or https scheme, got: '" . $scheme . "'"
            );
        }

        $host = $parts['host'] ?? '';
        if (!is_string($host) || $host === '') {
            throw new RuntimeException('WebhookChannel URL must include a host');
        }
    }

    /**
     * Resolve the target host and reject any resolution that lands
     * inside a blocked range. Called from `deliver()` rather than the
     * constructor so a long-lived channel with a DNS entry that moved
     * is still checked on use, and so tests and trusted internal
     * callers can opt out via `allowInternalTargets`.
     */
    private function assertHostNotInternal(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new RuntimeException('WebhookChannel URL must include a host');
        }

        // parse_url() leaves IPv6 literals wrapped in brackets; strip them
        // so inet_pton/gethostbynamel see the bare address.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            throw new RuntimeException("WebhookChannel could not resolve host: {$host}");
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new RuntimeException(
                    "WebhookChannel SSRF protection: host '{$host}' resolves to blocked IP {$ip}. " .
                    "Pass allowInternalTargets: true to the constructor for trusted internal webhooks."
                );
            }
        }
    }

    private function isBlockedIp(string $ip): bool
    {
        if (@inet_pton($ip) === false) {
            return true;
        }
        foreach (self::BLOCKED_IP_RANGES as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }
        [$range, $bitsStr] = explode('/', $cidr, 2);
        if ($bitsStr === '' || !ctype_digit($bitsStr)) {
            return false;
        }
        $bits = (int) $bitsStr;

        $ipPacked = @inet_pton($ip);
        $rangePacked = @inet_pton($range);
        if ($ipPacked === false || $rangePacked === false) {
            return false;
        }

        $addrLen = strlen($ipPacked);
        if ($addrLen !== strlen($rangePacked)) {
            return false;
        }

        $maxBits = $addrLen * 8;
        if ($bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if (substr($ipPacked, 0, $fullBytes) !== substr($rangePacked, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits > 0 && $fullBytes < $addrLen) {
            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            if ((ord($ipPacked[$fullBytes]) & $mask) !== (ord($rangePacked[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
