<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Core\ServiceProvider;
use Fw\Providers\AuxServiceProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AuxServiceProviderTest extends TestCase
{
    // ── B3: AuxServiceProvider must override boot() to register routes ────────

    public function testBootIsOverriddenNotInherited(): void
    {
        $rc = new ReflectionClass(AuxServiceProvider::class);
        $method = $rc->getMethod('boot');

        $this->assertSame(
            AuxServiceProvider::class,
            $method->getDeclaringClass()->getName(),
            'AuxServiceProvider must declare its own boot() to register routes',
        );
    }

    public function testBootMethodBodyDeclaresRoutes(): void
    {
        $rc = new ReflectionClass(AuxServiceProvider::class);
        $method = $rc->getMethod('boot');

        $file = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($file, $start, $end - $start));

        $this->assertStringContainsString('/mcp', $body, 'boot() must register /mcp group');
        $this->assertStringContainsString('/sse', $body, 'boot() must register /mcp/sse');
        $this->assertStringContainsString('/messages', $body, 'boot() must register /mcp/messages');
        $this->assertStringContainsString('/agent', $body, 'boot() must register /agent group');
        $this->assertStringContainsString('/tools', $body, 'boot() must register /agent/tools');
        $this->assertStringContainsString('McpSseController', $body);
        $this->assertStringContainsString('AgentController', $body);
    }
}
