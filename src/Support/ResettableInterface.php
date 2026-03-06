<?php

declare(strict_types=1);

namespace Fw\Support;

/**
 * Interface for services that need to be reset between requests.
 *
 * Used in persistent runtimes (worker mode, FrankenPHP) to prevent
 * memory leaks and cross-request state contamination.
 */
interface ResettableInterface
{
    /**
     * Reset the service state to its initial values.
     */
    public function reset(): void;
}
