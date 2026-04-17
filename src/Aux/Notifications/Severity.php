<?php

declare(strict_types=1);

namespace Fw\Aux\Notifications;

enum Severity: string
{
    case Info = 'info';
    case Warn = 'warn';
    case Error = 'error';
}
