<?php

declare(strict_types=1);

namespace Fw\Auth;

use Exception;
use Fw\Core\PrescriptiveException;

final class ForbiddenException extends Exception implements PrescriptiveException
{
    public function __construct(string $message = '')
    {
        parent::__construct(
            $message ?: "This action is unauthorized.\n"
                . "Fix: Check middleware and authorization rules:\n"
                . "  {$this->getFixCommand()}",
            403
        );
    }

    public function getFixCommand(): string
    {
        return 'php fw routes:list --middleware';
    }
}
