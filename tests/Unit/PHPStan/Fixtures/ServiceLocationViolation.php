<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\PHPStan\Fixtures;

use Fw\Core\Container;

class ServiceLocationViolation
{
    public function bad(): void
    {
        $container = Container::getInstance();
        $service = $container->get(SomeService::class);
    }
}
