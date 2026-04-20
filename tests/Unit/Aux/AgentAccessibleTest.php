<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\AgentAccessible;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AgentAccessibleTest extends TestCase
{
    public function testAttributeCarriesToolName(): void
    {
        $attribute = new AgentAccessible(tool: 'process_ticket_queue');

        $this->assertSame('process_ticket_queue', $attribute->tool);
    }

    public function testAttributeReadableViaReflection(): void
    {
        $method = new ReflectionMethod(AgentAccessibleFixtureController::class, 'index');
        $attrs = $method->getAttributes(AgentAccessible::class);

        $this->assertCount(1, $attrs);

        /** @var AgentAccessible $instance */
        $instance = $attrs[0]->newInstance();
        $this->assertSame('onboard_customer', $instance->tool);
    }

    public function testAttributeIsRepeatable(): void
    {
        $method = new ReflectionMethod(AgentAccessibleFixtureController::class, 'multi');
        $attrs = $method->getAttributes(AgentAccessible::class);

        $this->assertCount(2, $attrs);
    }
}

final class AgentAccessibleFixtureController
{
    #[AgentAccessible(tool: 'onboard_customer')]
    public function index(): void {}

    #[AgentAccessible(tool: 'one')]
    #[AgentAccessible(tool: 'two')]
    public function multi(): void {}
}
