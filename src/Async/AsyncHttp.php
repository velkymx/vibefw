<?php

declare(strict_types=1);

namespace Fw\Async;

use RuntimeException;
use Throwable;

/**
 * Truly non-blocking HTTP client using Fibers and EventLoop.
 */
final class AsyncHttp
{
    /**
     * IP ranges that are blocked by default to prevent SSRF attacks.
     * Includes private, loopback, link-local, and cloud metadata ranges.
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

    private array $defaultHeaders = [
        'User-Agent' => 'Fw-AsyncHttp/1.0',
        'Accept' => 'application/json',
        'Connection' => 'close',
    ];

    private int $timeout = 30;

    /**
     * Whether SSRF protection is enabled (default: true).
     * Disable only for trusted internal services.
     */
    private bool $ssrfProtection = true;

    /**
     * Disable SSRF protection for trusted internal service calls.
     */
    public function withoutSsrfProtection(): self
    {
        $clone = clone $this;
        $clone->ssrfProtection = false;
        return $clone;
    }

    public function request(string $method, string $url, mixed $body = null, array $headers = []): Deferred
    {
        $deferred = new Deferred();
        $loop = EventLoop::getInstance();

        $loop->defer(function () use ($deferred, $method, $url, $body, $headers, $loop): void {
            try {
                $this->executeAsync($deferred, $method, $url, $body, $headers, $loop);
            } catch (Throwable $e) {
                $deferred->reject($e);
            }
        });

        return $deferred;
    }

    public function get(string $url, array $headers = []): Deferred
    {
        return $this->request('GET', $url, null, $headers);
    }

    public function post(string $url, mixed $body = null, array $headers = []): Deferred
    {
        return $this->request('POST', $url, $body, $headers);
    }

    private function executeAsync(Deferred $deferred, string $method, string $url, mixed $body, array $headers, EventLoop $loop): void
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        $scheme = $parsed['scheme'] ?? 'http';
        $port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);
        $path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');

        if ($host === '') {
            throw new RuntimeException('Invalid URL: missing host');
        }

        // SSRF protection: resolve hostname and reject private/internal IPs
        if ($this->ssrfProtection) {
            $this->validateHost($host);
        }

        $transport = $scheme === 'https' ? 'ssl' : 'tcp';
        $address = "{$transport}://{$host}:{$port}";

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            (float) $this->timeout,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
        );

        if (!$socket) {
            throw new RuntimeException("Could not connect to {$address}: {$errstr}");
        }

        stream_set_blocking($socket, false);

        // Build request
        $allHeaders = array_merge($this->defaultHeaders, $headers);
        $allHeaders['Host'] = $host;

        $content = '';
        if ($body !== null) {
            $content = is_array($body) ? json_encode($body) : (string) $body;
            $allHeaders['Content-Length'] = (string) strlen($content);
            if (is_array($body) && !isset($allHeaders['Content-Type'])) {
                $allHeaders['Content-Type'] = 'application/json';
            }
        }

        // Validate headers against CRLF injection
        foreach ($allHeaders as $name => $value) {
            if (preg_match("/[\r\n]/", (string) $name) || preg_match("/[\r\n]/", (string) $value)) {
                throw new RuntimeException("HTTP header injection detected in '{$name}'");
            }
        }

        $request = "{$method} {$path} HTTP/1.1\r\n";
        foreach ($allHeaders as $name => $value) {
            $request .= "{$name}: {$value}\r\n";
        }
        $request .= "\r\n" . $content;

        $written = 0;
        $writeWatcherId = null;
        $writeWatcherId = $loop->addWriteStream($socket, function ($socket) use ($request, &$written, $loop, $deferred, &$writeWatcherId): void {
            $result = fwrite($socket, substr($request, $written));
            if ($result === false) {
                if ($writeWatcherId !== null) {
                    $loop->removeWriteStream($writeWatcherId, true);
                }
                $deferred->reject(new RuntimeException("Write failed"));
                return;
            }
            $written += $result;
            if ($written >= strlen($request)) {
                if ($writeWatcherId !== null) {
                    $loop->removeWriteStream($writeWatcherId);
                }
                $this->waitForResponse($socket, $loop, $deferred);
            }
        });
    }

    private function waitForResponse($socket, EventLoop $loop, Deferred $deferred): void
    {
        $buffer = '';
        $readWatcherId = null;
        $readWatcherId = $loop->addReadStream($socket, function ($socket) use (&$buffer, $loop, $deferred, &$readWatcherId): void {
            $chunk = fread($socket, 8192);
            if ($chunk === false) {
                if ($readWatcherId !== null) {
                    $loop->removeReadStream($readWatcherId, true);
                }
                $deferred->reject(new RuntimeException("Read failed"));
                return;
            }

            if ($chunk === '') {
                if (feof($socket)) {
                    if ($readWatcherId !== null) {
                        $loop->removeReadStream($readWatcherId, true);
                    }
                    $this->parseResponse($buffer, $deferred);
                }
                return;
            }

            $buffer .= $chunk;
        });
    }

    private function parseResponse(string $buffer, Deferred $deferred): void
    {
        $parts = explode("\r\n\r\n", $buffer, 2);
        if (count($parts) < 2) {
            $deferred->reject(new RuntimeException("Invalid response: missing header/body separator"));
            return;
        }

        $headerLines = explode("\r\n", $parts[0]);
        $statusLine  = array_shift($headerLines);

        preg_match('/HTTP\/[\d.]+\s+(\d+)/', $statusLine, $matches);
        $statusCode = isset($matches[1]) ? (int) $matches[1] : 200;

        $headers = [];
        foreach ($headerLines as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        $body = $parts[1];

        // RFC 7230 §4.1 — decode chunked transfer encoding when indicated.
        if (($headers['transfer-encoding'] ?? '') === 'chunked') {
            $body = $this->decodeChunked($body);
        }

        $deferred->resolve(new HttpResponse($statusCode, $headers, $body));
    }

    /**
     * Decode an HTTP/1.1 chunked transfer-encoded body (RFC 7230 §4.1).
     *
     * Format: {hex_size}[; ext]\r\n{data}\r\n ... 0\r\n\r\n
     * Chunk extensions (after ';') are ignored per spec.
     */
    private function decodeChunked(string $body): string
    {
        $decoded = '';
        $offset  = 0;
        $len     = strlen($body);

        while ($offset < $len) {
            // Find end of chunk-size line
            $eol = strpos($body, "\r\n", $offset);
            if ($eol === false) {
                break;
            }

            // Parse hex size (ignore optional chunk extensions after ';')
            $sizeLine  = substr($body, $offset, $eol - $offset);
            $semiColon = strpos($sizeLine, ';');
            if ($semiColon !== false) {
                $sizeLine = substr($sizeLine, 0, $semiColon);
            }

            $chunkSize = (int) hexdec(trim($sizeLine));
            $offset    = $eol + 2; // skip \r\n

            if ($chunkSize === 0) {
                break; // last chunk
            }

            $decoded .= substr($body, $offset, $chunkSize);
            $offset  += $chunkSize + 2; // skip data + trailing \r\n
        }

        return $decoded;
    }

    /**
     * Validate that the target host is not a private/internal address (SSRF protection).
     *
     * @throws RuntimeException If the host resolves to a blocked IP range
     */
    private function validateHost(string $host): void
    {
        // Resolve hostname to IP
        $ips = gethostbynamel($host);

        if ($ips === false) {
            throw new RuntimeException("Could not resolve hostname: {$host}");
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new RuntimeException(
                    "SSRF protection: request to {$host} blocked (resolves to private/internal IP {$ip}). " .
                    "Use withoutSsrfProtection() for trusted internal services."
                );
            }
        }
    }

    /**
     * Check if an IP address falls within any blocked range.
     */
    private function isBlockedIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true; // Invalid IP — block by default
        }

        foreach (self::BLOCKED_IP_RANGES as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IP address is within a CIDR range.
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$range, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $ipPacked = @inet_pton($ip);
        $rangePacked = @inet_pton($range);

        if ($ipPacked === false || $rangePacked === false) {
            return false;
        }

        // IPv4 and IPv6 have different lengths — must match
        if (strlen($ipPacked) !== strlen($rangePacked)) {
            return false;
        }

        // Compare the first $bits bits
        $fullBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        // Compare full bytes
        if (substr($ipPacked, 0, $fullBytes) !== substr($rangePacked, 0, $fullBytes)) {
            return false;
        }

        // Compare remaining bits
        if ($remainingBits > 0 && $fullBytes < strlen($ipPacked)) {
            $mask = 0xFF << (8 - $remainingBits);
            if ((ord($ipPacked[$fullBytes]) & $mask) !== (ord($rangePacked[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
