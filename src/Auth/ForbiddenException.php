<?php

declare(strict_types=1);

namespace Fw\Auth;

use Exception;

/**
 * Thrown when a user attempts an unauthorized action.
 */
final class ForbiddenException extends Exception
{
    public function __construct(string $message = '')
    {
        parent::__construct(
            $message ?: "This action is unauthorized.\n"
                . "Fix: Check middleware and authorization rules:\n"
                . "  php fw routes:list --middleware",
            403
        );
    }
}
