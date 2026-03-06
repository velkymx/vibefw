<?php

declare(strict_types=1);

namespace Fw\Core;

final class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private string $body = '';

    public function __construct(string $body = '', int $statusCode = 200)
    {
        $this->body = $body;
        $this->statusCode = $statusCode;
    }

    public const array STATUS_TEXTS = [
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        304 => 'Not Modified',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function setStatus(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->validateHeader($name, $value);
        $this->headers[$name] = $value;
        return $this;
    }

    public function headers(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->validateHeader($name, $value);
            $this->headers[$name] = $value;
        }
        return $this;
    }

    private function validateHeader(string $name, string $value): void
    {
        if (preg_match("/[\r\n]/", $name) || preg_match("/[\r\n]/", $value)) {
            throw new \InvalidArgumentException('Header contains CRLF');
        }
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9\-]*$/', $name)) {
            throw new \InvalidArgumentException("Invalid header name: {$name}");
        }
        if (str_contains($name, "\0") || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('Header contains null bytes');
        }
    }

    public function contentType(string $type, ?string $charset = 'UTF-8'): self
    {
        $this->headers['Content-Type'] = $charset !== null && $charset !== ''
            ? "$type; charset=$charset"
            : $type;
        return $this;
    }

    /**
     * Set a redirect.
     */
    public function redirect(string $url, int $code = 302): self
    {
        $this->setStatus($code);
        $this->header('Location', $url);
        return $this;
    }

    public function cache(int $seconds, bool $public = true): self
    {
        $directive = $public ? 'public' : 'private';
        $this->header('Cache-Control', "$directive, max-age=$seconds");
        $this->header('Expires', gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT');
        return $this;
    }

    public function noCache(): self
    {
        $this->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $this->header('Pragma', 'no-cache');
        $this->header('Expires', '0');
        return $this;
    }

    /**
     * Add data to be flashed to the session.
     */
    public function with(string $key, mixed $value): self
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['_flash'][$key] = $value;
        return $this;
    }

    /**
     * Add validation errors to be flashed to the session.
     */
    public function withErrors(array $errors): self
    {
        return $this->with('errors', $errors);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Get the status text for the current status code.
     */
    public function getStatusText(): string
    {
        return self::STATUS_TEXTS[$this->statusCode] ?? 'Unknown';
    }
}
