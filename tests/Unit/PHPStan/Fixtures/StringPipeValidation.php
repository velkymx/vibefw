<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\PHPStan\Fixtures;

class StringPipeValidation
{
    /**
     * @return array<string, string>
     */
    public function getRules(): array
    {
        return [
            'email' => 'required|email',
            'name' => 'required|min:3',
        ];
    }
}
