<?php

declare(strict_types=1);

namespace Fw\Core;

/**
 * Streamed response for large content or real-time output.
 *
 * Instead of buffering the entire response in memory, this streams
 * content directly to the client. Useful for:
 * - Large HTML pages
 * - Real-time updates
 * - Memory-constrained environments
 *
 * Usage:
 *   return new StreamedResponse(function() use ($view, $data) {
 *       $view->stream('large-page', $data);
 *   });
 */
final class StreamedResponse
{
    /** @var callable */
    private $callback;

    private int $statusCode;

    /** @var array<string, string> */
    private array $headers;

    /**
     * @param callable $callback Function that outputs content
     * @param int $statusCode HTTP status code
     * @param array<string, string> $headers HTTP headers
     */
    public function __construct(
        callable $callback,
        int $statusCode = 200,
        array $headers = [],
    ) {
        $this->callback = $callback;
        $this->statusCode = $statusCode;
        $this->headers = array_merge([
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Accel-Buffering' => 'no', // Disable nginx buffering
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'no-referrer-when-downgrade',
        ], $headers);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Send the streamed response.
     */
    public function send(): void
    {
        // Send status
        http_response_code($this->statusCode);

        // Send headers
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }

        // Disable output buffering for true streaming
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        // Flush headers immediately
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }

        // Execute the callback (which outputs content)
        ($this->callback)();

        // Ensure everything is sent
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    /**
     * Check if this is a streamed response.
     */
    public function isStreamed(): bool
    {
        return true;
    }
}
