<?php

declare(strict_types=1);

namespace Fw\Core;

use InvalidArgumentException;

final class Response
{
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

    // Intentionally excludes `data:` from img-src — it is a known XSS/exfil
    // vector when user-uploaded markup is rendered. Apps that need inline
    // image data URIs must opt in via Response::setDefaultCsp().
    public const string DEFAULT_CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self';";

    private static ?string $defaultCsp = null;

    private int $statusCode = 200;

    private array $headers = [];

    private string $body = '';

    private array $flash = [];

    public function __construct(string $body = '', int $statusCode = 200)
    {
        $this->body = $body;
        $this->statusCode = $statusCode;
    }

    public static function setDefaultCsp(?string $csp): void
    {
        self::$defaultCsp = $csp;
    }

    public static function cspMetaTag(?string $csp = null): string
    {
        $policy = $csp ?? self::$defaultCsp ?? self::DEFAULT_CSP;
        $escaped = htmlspecialchars($policy, ENT_QUOTES, 'UTF-8');
        return '<meta http-equiv="Content-Security-Policy" content="' . $escaped . '">';
    }

    public function setBody(string $body): self
    {
        $new = clone $this;
        $new->body = $body;
        return $new;
    }

    public function setStatus(int $code): self
    {
        $new = clone $this;
        $new->statusCode = $code;
        return $new;
    }

    public function header(string $name, string $value): self
    {
        $this->validateHeader($name, $value);
        $new = clone $this;
        $new->headers[$name] = $value;
        return $new;
    }

    public function headers(array $headers): self
    {
        $new = clone $this;
        foreach ($headers as $name => $value) {
            $new->validateHeader($name, $value);
            $new->headers[$name] = $value;
        }
        return $new;
    }

    public function contentType(string $type, ?string $charset = 'UTF-8'): self
    {
        $new = clone $this;
        $new->headers['Content-Type'] = $charset !== null && $charset !== ''
            ? "$type; charset=$charset"
            : $type;
        return $new;
    }

    /**
     * Set a redirect.
     *
     * Permanent redirects (301, 308) are emitted cacheable for a year so
     * browsers and proxies stop re-requesting the old URL; temporary
     * redirects (302, 303, 307) get Cache-Control: no-store to keep
     * intermediaries from pinning a target that may move again. Callers
     * can still override either by chaining ->header() afterwards.
     */
    public function redirect(string $url, int $code = 302): self
    {
        $response = $this->setStatus($code)->header('Location', $url);

        return match ($code) {
            301, 308 => $response
                ->header('Cache-Control', 'public, max-age=31536000')
                ->header('Expires', gmdate('D, d M Y H:i:s', time() + 31_536_000) . ' GMT'),
            default => $response->header('Cache-Control', 'no-store'),
        };
    }

    /**
     * Set standard security headers.
     */
    public function securityHeaders(?string $csp = null): self
    {
        $policy = $csp ?? self::$defaultCsp ?? self::DEFAULT_CSP;

        return $this->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('X-XSS-Protection', '1; mode=block')
            ->header('Referrer-Policy', 'no-referrer-when-downgrade')
            ->header('Content-Security-Policy', $policy);
    }

    public function cache(int $seconds, bool $public = true): self
    {
        $directive = $public ? 'public' : 'private';
        return $this->header('Cache-Control', "$directive, max-age=$seconds")
            ->header('Expires', gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT');
    }

    public function noCache(): self
    {
        return $this->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /**
     * Add data to be flashed to the session.
     */
    public function with(string $key, mixed $value): self
    {
        $new = clone $this;
        $new->flash[$key] = $value;
        return $new;
    }

    /**
     * Add validation errors to be flashed to the session.
     */
    public function withErrors(array $errors): self
    {
        return $this->with('errors', $errors);
    }

    /**
     * Get flash data.
     */
    public function getFlash(): array
    {
        return $this->flash;
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

    private function validateHeader(string $name, string $value): void
    {
        if (preg_match("/[\r\n]/", $name) || preg_match("/[\r\n]/", $value)) {
            throw new InvalidArgumentException('Header contains CRLF');
        }
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9\-]*$/', $name)) {
            throw new InvalidArgumentException("Invalid header name: {$name}");
        }
        if (str_contains($name, "\0") || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Header contains null bytes');
        }
    }
}
