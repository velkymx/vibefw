<?php

declare(strict_types=1);

namespace Fw\Async;

/**
 * Simple value object representing an HTTP response from AsyncHttp.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    /**
     * Decode the response body as JSON.
     *
     * @return mixed
     */
    public function json(bool $assoc = true): mixed
    {
        return json_decode($this->body, $assoc, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Check if the response indicates success (2xx status).
     */
    public function isOk(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Get a specific header value (case-insensitive).
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
