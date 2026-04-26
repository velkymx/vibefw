<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Validation\Rules;

use BackedEnum;

/**
 * Test enum for InEnum rule testing.
 */
enum TestStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
}
