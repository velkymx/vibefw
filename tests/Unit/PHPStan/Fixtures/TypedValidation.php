<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\PHPStan\Fixtures;

class TypedValidation
{
    /**
     * @return array<string, list<object>>
     */
    public function getRules(): array
    {
        return [
            'email' => [new \stdClass()],
            'name' => [new \stdClass()],
        ];
    }
}
