<?php

declare(strict_types=1);

namespace Fw\Aux\Exceptions;

use RuntimeException;

final class ToolNotFoundException extends RuntimeException
{
    public function __construct(string $toolName)
    {
        parent::__construct("Tool '{$toolName}' not found");
    }
}
