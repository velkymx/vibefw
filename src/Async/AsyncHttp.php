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
     * RFC 7230 §3.2.6 token grammar — the only bytes permitted in a
     * header name. No spaces, no separators, no control chars, no
     * non-ASCII.
     */
    private const string HEADER_NAME_TOKEN = "/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/";

    /**
     * Reject header name/value pairs that could enable response
     * splitting or request smuggling. The old `[\r\n]`-only check
     * missed every other control byte (NUL, DEL, VT, FF, bare CR/LF)
     * and silently accepted header names containing spaces, colons,
     * tabs, or non-ASCII — all of which mangle the wire format.
     *
     * Rules:
     *   - Name must be a non-empty RFC 7230 token.
     *   - Value must contain no C0 control byte except HTAB, and no
     *     DEL (0x7F). High bytes (0x80–0xFF) are permitted as
     *     obs-text, matching PHP/cURL behavior.
     *
     * @throws RuntimeException with a sanitized (hex-escaped) marker
     *     identifying the offending header, so the exception message
     *     itself can't inherit injected control characters.
     */
    private static function assertValidHeader(string $name, string $value): void
    {
        if ($name === '' || preg_match(self::HEADER_NAME_TOKEN, $name) !== 1) {
            throw new RuntimeException(
                "HTTP header injection detected: invalid name '" . self::sanitizeForError($name) . "'"
            );
        }

        // Any byte below SP except HTAB, plus DEL.
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
            throw new RuntimeException(
                "HTTP header injection detected in '{$name}': value contains control bytes"
            );
        }
    }

    private static function sanitizeForError(string $s): string
    {
        return preg_replace_callback(
            '/[^\x20-\x7E]/',
            static fn (array $m): string => sprintf('\\x%02X', ord($m[0])),
            $s
        ) ?? '';
    }

    /**
     * Disable SSRF protection for trusted internal service calls.
     */
    public function withoutSsrfProtection(): self
    {
        $clone = clone $this;
        $clone->ssrfProtection = false;
        return $clone;
    }

    /**
     * Enable HTTP keep-alive for this client instance.
     *
     * By default AsyncHttp sends Connection: close, which lets response
     * reading terminate naturally on EOF. Use keep-alive together with
     * servers that include Content-Length or Transfer-Encoding: chunked,
     * both of which AsyncHttp's isResponseComplete() handles automatically.
     */
    public function withKeepAlive(): self
    {
        $clone = clone $this;
        $clone->defaultHeaders['Connection'] = 'keep-alive';
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

        // SSRF protection: resolve hostname once and pin the connect to
        // the approved IP. This closes the DNS-rebinding window between
        // validation and the subsequent stream_socket_client() lookup.
        // When protection is off we fall back to the hostname literal
        // so public DNS still does its job.
        $connectTarget = $this->ssrfProtection ? $this->validateHost($host) : $host;

        $transport = $scheme === 'https' ? 'ssl' : 'tcp';
        $address = "{$transport}://{$connectTarget}:{$port}";

        // For TLS connections to a pinned IP we must tell OpenSSL which
        // SNI to send and which name to match the cert against —
        // otherwise peer verification fails because the IP doesn't
        // appear in the certificate's SANs.
        $context = stream_context_create(
            $scheme === 'https'
                ? [
                    'ssl' => [
                        'peer_name' => $host,
                        'SNI_enabled' => true,
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                    ],
                ]
                : []
        );

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            (float) $this->timeout,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $context
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

        foreach ($allHeaders as $name => $value) {
            self::assertValidHeader((string) $name, (string) $value);
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

            // Short-circuit for responses with explicit length metadata so
            // keep-alive connections resolve without waiting for EOF.
            if ($this->isResponseComplete($buffer)) {
                if ($readWatcherId !== null) {
                    $loop->removeReadStream($readWatcherId, true);
                }
                $this->parseResponse($buffer, $deferred);
            }
        });
    }

    /**
     * Determine whether all response bytes have been received.
     *
     * Returns true when:
     * - Transfer-Encoding is chunked and the 0\r\n\r\n terminator is present.
     * - Content-Length is declared and that many body bytes have arrived.
     *
     * Returns false (caller continues reading until EOF) when neither header
     * is present — this is the correct behaviour for Connection: close.
     */
    private function isResponseComplete(string $buffer): bool
    {
        $sepPos = strpos($buffer, "\r\n\r\n");
        if ($sepPos === false) {
            return false; // Headers not fully received yet
        }

        $headerSection = substr($buffer, 0, $sepPos);
        $bodyStart = $sepPos + 4;
        $bodyReceived = strlen($buffer) - $bodyStart;

        // Parse headers once from the header section
        $headers = [];
        foreach (explode("\r\n", $headerSection) as $i => $line) {
            if ($i === 0 || !str_contains($line, ':')) {
                continue; // Skip status line and malformed headers
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        if (($headers['transfer-encoding'] ?? '') === 'chunked') {
            // Chunked: look for the zero-length terminator chunk
            return str_ends_with(substr($buffer, $bodyStart), "0\r\n\r\n");
        }

        if (isset($headers['content-length'])) {
            return $bodyReceived >= (int) $headers['content-length'];
        }

        // No length metadata — must wait for EOF (Connection: close behaviour)
        return false;
    }

    private function parseResponse(string $buffer, Deferred $deferred): void
    {
        $parts = explode("\r\n\r\n", $buffer, 2);
        if (count($parts) < 2) {
            $deferred->reject(new RuntimeException("Invalid response: missing header/body separator"));
            return;
        }

        $headerLines = explode("\r\n", $parts[0]);
        $statusLine = array_shift($headerLines);

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
        $offset = 0;
        $len = strlen($body);

        while ($offset < $len) {
            // Find end of chunk-size line
            $eol = strpos($body, "\r\n", $offset);
            if ($eol === false) {
                break;
            }

            // Parse hex size (ignore optional chunk extensions after ';')
            $sizeLine = substr($body, $offset, $eol - $offset);
            $semiColon = strpos($sizeLine, ';');
            if ($semiColon !== false) {
                $sizeLine = substr($sizeLine, 0, $semiColon);
            }

            $chunkSize = (int) hexdec(trim($sizeLine));
            $offset = $eol + 2; // skip \r\n

            if ($chunkSize === 0) {
                break; // last chunk
            }

            $decoded .= substr($body, $offset, $chunkSize);
            $offset += $chunkSize + 2; // skip data + trailing \r\n
        }

        return $decoded;
    }

    /**
     * Validate that the target host is not a private/internal address (SSRF protection).
     *
     * Returns the first safe resolved IP so the caller can connect
     * directly to it — closing the DNS-rebinding window between
     * validation and the actual `stream_socket_client()` resolve.
     *
     * @throws RuntimeException If the host resolves to a blocked IP range or cannot be resolved.
     */
    private function validateHost(string $host): string
    {
        // If the caller handed us a literal IP, honour it directly so
        // numeric hosts don't round-trip through gethostbynamel (which
        // still works for IPv4 literals but is wasted work).
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if ($this->isBlockedIp($host)) {
                throw new RuntimeException(
                    "SSRF protection: request to {$host} blocked (resolves to private/internal IP {$host}). " .
                    "Use withoutSsrfProtection() for trusted internal services."
                );
            }
            return $host;
        }

        $ips = gethostbynamel($host);

        if ($ips === false || $ips === []) {
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

        return $ips[0];
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
