<?php

declare(strict_types=1);

namespace Fw\Aux;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class AgentAccessible
{
    public function __construct(public string $tool) {}
}
