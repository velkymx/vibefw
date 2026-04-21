<?php

declare(strict_types=1);

namespace Fw\Core;

use Fw\Security\Sanitizer;
use InvalidArgumentException;
use RuntimeException;

final class Request
{
    private static int $maxBodySize = 10 * 1024 * 1024;

    private static array $trustedProxies = [];

    private static int $readTimeout = 30;

    /** Minimum valid bearer token length (bytes) — matches Csrf::TOKEN_BYTES. */
    private const int BEARER_TOKEN_MIN = 32;

    /** Maximum valid bearer token length (bytes) — prevents oversized Authorization headers. */
    private const int BEARER_TOKEN_MAX = 512;

    public readonly string $method;

    public readonly string $uri;

    public readonly string $fullUri;

    public readonly array $query;

    public readonly array $post;

    public readonly array $server;

    public readonly array $files;

    public readonly array $headers;

    /**
     * @param array<string, mixed>|null $parsedJson
     */
    public function __construct(
        ?array $query = null,
        ?array $post = null,
        ?array $server = null,
        ?array $files = null,
        ?array $headers = null,
        private ?string $rawBody = null,
        private ?array $parsedJson = null,
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

    /**
     * Set the maximum raw request body size in bytes. Reads exceeding this limit
     * throw RuntimeException. Wire from config (e.g. REQUEST_MAX_BODY_SIZE) at
     * bootstrap — same opt-in pattern as setTrustedProxies().
     */
    public static function setMaxBodySize(int $bytes): void
    {
        if ($bytes <= 0) {
            throw new InvalidArgumentException('Request max body size must be positive, got ' . $bytes);
        }
        self::$maxBodySize = $bytes;
    }

    public static function getMaxBodySize(): int
    {
        return self::$maxBodySize;
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
        $json = $this->json() ?? [];
        return $this->post[$key] ?? $json[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        $body = !empty($this->post) ? $this->post : ($this->json() ?? []);
        return array_merge($this->query, $body);
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
        return array_key_exists($key, $this->all());
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
        $weights = self::parseAcceptWeights($this->header('accept', ''));

        $json = $weights['application/json'] ?? null;
        $html = $weights['text/html'] ?? null;
        $wildcard = $weights['*/*'] ?? null;

        // Ties and missing JSON fall through to HTML when the client announced it;
        // browsers routinely send `text/html,*/*;q=0.8`, which must render HTML.
        if ($json !== null && $json > 0.0) {
            return $html === null || $json > $html;
        }

        return $wildcard !== null && $wildcard > 0.0 && $html === null;
    }

    /**
     * Parse an Accept header into a media-type → q-value map. Unparseable
     * q values fall back to the RFC 7231 default of 1.0; malformed entries
     * are skipped silently.
     *
     * @return array<string, float>
     */
    private static function parseAcceptWeights(string $accept): array
    {
        if ($accept === '') {
            return [];
        }

        $weights = [];
        foreach (explode(',', $accept) as $part) {
            $segments = explode(';', trim($part));
            $type = strtolower(trim(array_shift($segments)));
            if ($type === '') {
                continue;
            }

            $q = 1.0;
            foreach ($segments as $param) {
                $param = trim($param);
                if (str_starts_with($param, 'q=')) {
                    $q = (float) substr($param, 2);
                    break;
                }
            }

            $weights[$type] = isset($weights[$type]) ? max($weights[$type], $q) : $q;
        }

        return $weights;
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
        if ($this->parsedJson !== null) {
            return $this->parsedJson;
        }
        if (!$this->isJson()) {
            return null;
        }
        $data = json_decode($this->rawBody(), true);
        return $this->parsedJson = is_array($data) ? $data : null;
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
        if (!str_starts_with($auth, 'Bearer ')) {
            return null;
        }
        $token = substr($auth, 7);
        $len = strlen($token);
        return ($len >= self::BEARER_TOKEN_MIN && $len <= self::BEARER_TOKEN_MAX) ? $token : null;
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
