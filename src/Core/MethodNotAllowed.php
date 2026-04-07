<?php

declare(strict_types=1);

namespace Fw\Core;

use RuntimeException;

/**
 * Exception thrown when a route exists but the HTTP method is not allowed.
 */
final class MethodNotAllowed extends RuntimeException
{
    /**
     * @param array<string> $allowedMethods
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $allowedMethods,
        string $message = '',
    ) {
        parent::__construct(
            $message ?: "Method {$method} not allowed for {$uri}. Allowed: " . implode(', ', $allowedMethods) . "\n"
                . "Fix: Use one of the allowed methods, or add a {$method} route in config/routes.php:\n"
                . "  php fw routes:list",
            405
        );
    }

    /**
     * Create from request details.
     *
     * @param array<string> $allowedMethods
     */
    public static function forRequest(string $method, string $uri, array $allowedMethods): self
    {
        return new self($method, $uri, $allowedMethods);
    }
}
