<?php

declare(strict_types=1);

namespace Fw\Core;

use RuntimeException;

/**
 * Exception thrown when no route matches the request.
 */
final class RouteNotFound extends RuntimeException
{
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        string $message = '',
    ) {
        parent::__construct(
            $message ?: "No route found for {$method} {$uri}\n"
                . "Fix: Register this route in config/routes.php, then verify with:\n"
                . "  php fw routes:list",
            404
        );
    }

    /**
     * Create from request details.
     */
    public static function forRequest(string $method, string $uri): self
    {
        return new self($method, $uri);
    }
}
