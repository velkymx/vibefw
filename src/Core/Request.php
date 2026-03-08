<?php

declare(strict_types=1);

namespace Fw\Core;

use Fw\Security\Sanitizer;
use RuntimeException;

final class Request
{
    private static int $maxBodySize = 10 * 1024 * 1024;

    private static array $trustedProxies = [];

    private static int $readTimeout = 30;

    public readonly string $method;

    public readonly string $uri;

    public readonly string $fullUri;

    public readonly array $query;

    public readonly array $post;

    public readonly array $server;

    public readonly array $files;

    public readonly array $headers;

    public function __construct(
        ?array $query = null,
        ?array $post = null,
        ?array $server = null,
        ?array $files = null,
        ?array $headers = null,
        private ?string $rawBody = null,
        ?string $method = null,
        ?string $uri = null
    ) {
        if ($query === null && $post === null && $server === null && $files === null && $headers === null) {
            $this->query = $_GET;
            $this->post = $_POST;
            $this->server = $_SERVER;
            $this->files = $_FILES;
            $this->headers = self::parseHeadersFromGlobals($_SERVER);
        } else {
            $this->query = $query ?? [];
            $this->post = $post ?? [];
            $this->server = $server ?? [];
            $this->files = $files ?? [];
            $this->headers = $headers ?? [];
        }

        $this->fullUri = $uri ?? $this->server['REQUEST_URI'] ?? '/';
        $this->uri = $this->parseUri($this->fullUri);
        $this->method = $method ?? $this->resolveMethod();
    }

    public static function createFromGlobals(): self
    {
        return new self();
    }

    /**
     * Set the list of trusted proxy IP addresses.
     *
     * @param array<string> $proxies
     */
    public static function setTrustedProxies(array $proxies): void
    {
        // Warn about wildcard trusted proxies — any client can spoof X-Forwarded-For
        if (in_array('*', $proxies, true)) {
            $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
            if ($env === 'production') {
                error_log(
                    'WARNING: Trusted proxies set to wildcard (*). Any client can spoof their IP address. ' .
                    'Use explicit proxy IPs (e.g. [\'10.0.0.1\', \'10.0.0.2\']) in production.'
                );
            }
        }
        self::$trustedProxies = $proxies;
    }

    public static function setReadTimeout(int $seconds): void
    {
        self::$readTimeout = $seconds;
    }

    private static function parseHeadersFromGlobals(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = (string) $value;
            }
        }
        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $server['CONTENT_TYPE'];
        }
        if (isset($server['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $server['CONTENT_LENGTH'];
        }
        return $headers;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    public function except(array $keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    public function has(string $key): bool
    {
        return isset($this->query[$key]) || isset($this->post[$key]);
    }

    public function query(): array
    {
        return $this->query;
    }

    public function postData(): array
    {
        return $this->post;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function files(): array
    {
        return $this->files;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function ip(): string
    {
        $remoteAddr = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';

        // Only trust X-Forwarded-For and Client-IP if the remote address is a trusted proxy
        // Or if trusted proxies is set to '*' (all proxies trusted - use with caution)
        $isTrusted = in_array($remoteAddr, self::$trustedProxies, true) ||
                     in_array('*', self::$trustedProxies, true);

        if ($isTrusted) {
            if (isset($this->server['HTTP_X_FORWARDED_FOR'])) {
                $ips = array_reverse(explode(',', $this->server['HTTP_X_FORWARDED_FOR']));
                foreach ($ips as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        // If this IP is NOT a trusted proxy, it's the real client IP
                        if (!in_array($ip, self::$trustedProxies, true) && !in_array('*', self::$trustedProxies, true)) {
                            return $ip;
                        }
                    }
                }
                // If all IPs in X-Forwarded-For are trusted proxies, the first one is the client
                $firstIp = trim(explode(',', $this->server['HTTP_X_FORWARDED_FOR'])[0]);
                if (filter_var($firstIp, FILTER_VALIDATE_IP)) {
                    return $firstIp;
                }
            }
            if (isset($this->server['HTTP_CLIENT_IP'])) {
                $clientIp = trim($this->server['HTTP_CLIENT_IP']);
                if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                    return $clientIp;
                }
            }
        }

        return $remoteAddr;
    }

    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    public function isSecure(): bool
    {
        if (($this->server['HTTPS'] ?? '') === 'on') {
            return true;
        }

        $remoteAddr = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
        $isTrusted = in_array($remoteAddr, self::$trustedProxies, true) ||
                     in_array('*', self::$trustedProxies, true);

        if ($isTrusted && isset($this->server['HTTP_X_FORWARDED_PROTO'])) {
            return strtolower($this->server['HTTP_X_FORWARDED_PROTO']) === 'https';
        }

        return false;
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('x-requested-with', '')) === 'xmlhttprequest';
    }

    public function isJson(): bool
    {
        return str_contains($this->header('content-type', ''), 'application/json');
    }

    public function expectsJson(): bool
    {
        $accept = $this->header('accept', '');

        // Always true when JSON is explicitly requested
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        // Bare */* is sent by browsers alongside text/html — do NOT treat that
        // as a JSON request, or HTML page visits break error handling.
        // Only accept */* when there is no explicit text/html preference.
        if (str_contains($accept, '*/*') && !str_contains($accept, 'text/html')) {
            return true;
        }

        return false;
    }

    public function wantsJson(): bool
    {
        // URI-prefix heuristic ('/api') removed: it matches /api-docs, /apiary, etc.
        // and returns JSON errors for browser HTML requests on those routes.
        // Rely solely on Accept header and X-Requested-With for content negotiation.
        return $this->expectsJson() || $this->isAjax();
    }

    public function json(): ?array
    {
        if (!$this->isJson()) {
            return null;
        }
        $data = json_decode($this->rawBody(), true);
        return is_array($data) ? $data : null;
    }

    public function rawBody(): string
    {
        if ($this->rawBody !== null) {
            return $this->rawBody;
        }
        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            return $this->rawBody = '';
        }
        stream_set_timeout($stream, self::$readTimeout);
        $body = '';
        $startTime = microtime(true);
        try {
            while (!feof($stream)) {
                if (microtime(true) - $startTime > (float) self::$readTimeout) {
                    throw new RuntimeException('Request body read timeout');
                }
                $chunk = fread($stream, 8192);
                if (stream_get_meta_data($stream)['timed_out']) {
                    throw new RuntimeException('Request body read timeout');
                }
                if ($chunk === false) {
                    break;
                }
                $body .= $chunk;
                if (strlen($body) >= self::$maxBodySize) {
                    throw new RuntimeException('Request body too large');
                }
            }
        } finally {
            fclose($stream);
        }
        return $this->rawBody = $body;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization', '');
        return str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : null;
    }

    public function sanitized(string $key, mixed $default = null): mixed
    {
        $value = $this->input($key, $default);
        return is_string($value) ? Sanitizer::html($value) : $value;
    }

    private function resolveMethod(): string
    {
        $method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST' && isset($this->post['_method'])) {
            $spoofed = strtoupper($this->post['_method']);
            if (in_array($spoofed, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $spoofed;
            }
        }
        return $method;
    }

    private function parseUri(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        return '/' . trim($path, '/');
    }
}
