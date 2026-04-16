<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Console\Commands\ServeMcpCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ServeMcpCommandTest extends TestCase
{
    // ── B4: ServeMcpCommand must not instantiate McpServer directly ───────────

    public function testHandleDoesNotInstantiateToolRegistryDirectly(): void
    {
        $rc = new ReflectionClass(ServeMcpCommand::class);
        $method = $rc->getMethod('handle');

        $file = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($file, $start, $end - $start));

        $this->assertStringNotContainsString(
            'new ToolRegistry()',
            $body,
            'ServeMcpCommand must not create a fresh ToolRegistry — use container',
        );
        $this->assertStringNotContainsString(
            'new McpProtocol(',
            $body,
            'ServeMcpCommand must not create McpProtocol directly — use container',
        );
        $this->assertStringNotContainsString(
            'new McpServer(',
            $body,
            'ServeMcpCommand must not create McpServer directly — use container',
        );
    }

    public function testHandleResolvesFromContainer(): void
    {
        $rc = new ReflectionClass(ServeMcpCommand::class);
        $method = $rc->getMethod('handle');

        $file = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($file, $start, $end - $start));

        $this->assertStringContainsString(
            'Application::getInstance()',
            $body,
            'ServeMcpCommand must use Application::getInstance() to resolve McpServer from container',
        );
    }
}
