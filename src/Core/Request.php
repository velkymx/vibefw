<?php

declare(strict_types=1);

namespace Fw\Core;

use Fw\Security\Sanitizer;

final class Request
{
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

    private static int $maxBodySize = 10 * 1024 * 1024;

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

    private static function parseHeadersFromGlobals(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = (string) $value;
            }
        }
        if (isset($server['CONTENT_TYPE'])) $headers['content-type'] = (string) $server['CONTENT_TYPE'];
        if (isset($server['CONTENT_LENGTH'])) $headers['content-length'] = (string) $server['CONTENT_LENGTH'];
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
        if (isset($this->server['HTTP_X_FORWARDED_FOR'])) {
            $forwardedFor = explode(',', $this->server['HTTP_X_FORWARDED_FOR']);
            $clientIp = trim($forwardedFor[0]);
            if (filter_var($clientIp, FILTER_VALIDATE_IP)) return $clientIp;
        }
        if (isset($this->server['HTTP_CLIENT_IP'])) {
            $clientIp = trim($this->server['HTTP_CLIENT_IP']);
            if (filter_var($clientIp, FILTER_VALIDATE_IP)) return $clientIp;
        }
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    public function isSecure(): bool
    {
        if (($this->server['HTTPS'] ?? '') === 'on') return true;
        return ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
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
        return str_contains($accept, 'application/json') || str_contains($accept, '*/*');
    }

    public function wantsJson(): bool
    {
        return $this->expectsJson() || $this->isAjax() || str_starts_with($this->uri, '/api');
    }

    public function json(): ?array
    {
        if (!$this->isJson()) return null;
        $data = json_decode($this->rawBody(), true);
        return is_array($data) ? $data : null;
    }

    private static int $readTimeout = 30;
    public static function setReadTimeout(int $seconds): void
    {
        self::$readTimeout = $seconds;
    }

    public function rawBody(): string
    {
        if ($this->rawBody !== null) return $this->rawBody;
        $stream = fopen('php://input', 'rb');
        if ($stream === false) return $this->rawBody = '';
        stream_set_timeout($stream, self::$readTimeout);
        $body = '';
        $startTime = microtime(true);
        try {
            while (!feof($stream)) {
                if (microtime(true) - $startTime > (float) self::$readTimeout) throw new \RuntimeException('Request body read timeout');
                $chunk = fread($stream, 8192);
                if (stream_get_meta_data($stream)['timed_out']) throw new \RuntimeException('Request body read timeout');
                if ($chunk === false) break;
                $body .= $chunk;
                if (strlen($body) > self::$maxBodySize) throw new \RuntimeException('Request body too large');
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
}
