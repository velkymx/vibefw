<?php

declare(strict_types=1);

namespace Fw\Core;

/**
 * Emitter for standard PHP SAPI environments (PHP-FPM, CLI-Server).
 */
final class SapiEmitter
{
    /**
     * Emit the response using standard PHP functions.
     */
    public function emit(Response $response): void
    {
        if (headers_sent()) {
            throw new \RuntimeException('Headers already sent');
        }

        $statusCode = $response->getStatusCode();
        $statusText = $response->getStatusText();
        
        // 1. Send status line
        header("HTTP/1.1 {$statusCode} {$statusText}", true, $statusCode);

        // 2. Send headers
        foreach ($response->getHeaders() as $name => $value) {
            header("{$name}: {$value}", false);
        }

        // 3. Send body
        $body = $response->getBody();
        if ($body !== '') {
            echo $body;
        }

        // 4. Flush buffers
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
}
