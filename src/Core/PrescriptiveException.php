<?php

declare(strict_types=1);

namespace Fw\Core;

interface PrescriptiveException
{
    /**
     * Get the CLI command that can fix or diagnose this error.
     */
    public function getFixCommand(): string;
}
