<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\PHPStan\Fixtures;

class ConstructorInjection
{
    public function __construct(
        private readonly SomeService $service,
    ) {}

    public function good(): void
    {
        $this->service->doSomething();
    }
}
