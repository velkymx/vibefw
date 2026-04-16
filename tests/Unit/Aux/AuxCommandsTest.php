<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Console\Commands\AuxListCommand;
use Fw\Console\Commands\AuxCallCommand;
use Fw\Console\Commands\AuxSchemaCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AuxCommandsTest extends TestCase
{
    // ── D1: aux:list ─────────────────────────────────────────────────────────

    public function testAuxListCommandExists(): void
    {
        $this->assertTrue(class_exists(AuxListCommand::class));
    }

    public function testAuxListCommandHasCorrectName(): void
    {
        $rc = new ReflectionClass(AuxListCommand::class);
        $prop = $rc->getProperty('name');
        $this->assertSame('aux:list', $prop->getDefaultValue());
    }

    public function testAuxListHandleResolvesToolRegistry(): void
    {
        $rc = new ReflectionClass(AuxListCommand::class);
        $method = $rc->getMethod('handle');

        $file = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($file, $start, $end - $start));

        $this->assertStringContainsString('ToolRegistry', $body);
        $this->assertStringContainsString('->all()', $body);
        $this->assertStringContainsString('table(', $body);
    }

    // ── D2: aux:call ─────────────────────────────────────────────────────────

    public function testAuxCallCommandExists(): void
    {
        $this->assertTrue(class_exists(AuxCallCommand::class));
    }

    public function testAuxCallCommandHasCorrectName(): void
    {
        $rc = new ReflectionClass(AuxCallCommand::class);
        $prop = $rc->getProperty('name');
        $this->assertSame('aux:call', $prop->getDefaultValue());
    }

    public function testAuxCallAcceptsToolNameArgument(): void
    {
        $rc = new ReflectionClass(AuxCallCommand::class);
        $method = $rc->getMethod('configure');

        $file = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($file, $start, $end - $start));

        $this->assertStringContainsString('name', $body);
    }

    public function testAuxCallUsesRegistryCall(): void
    {
        $rc = new ReflectionClass(AuxCallCommand::class);
        $method = $rc->getMethod('handle');

        $file = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($file, $start, $end - $start));

        $this->assertStringContainsString('ToolRegistry', $body);
        $this->assertStringContainsString('->call(', $body);
    }

    // ── D3: aux:schema ───────────────────────────────────────────────────────

    public function testAuxSchemaCommandExists(): void
    {
        $this->assertTrue(class_exists(AuxSchemaCommand::class));
    }

    public function testAuxSchemaCommandHasCorrectName(): void
    {
        $rc = new ReflectionClass(AuxSchemaCommand::class);
        $prop = $rc->getProperty('name');
        $this->assertSame('aux:schema', $prop->getDefaultValue());
    }

    public function testAuxSchemaOutputsJsonSchema(): void
    {
        $rc = new ReflectionClass(AuxSchemaCommand::class);
        $method = $rc->getMethod('handle');

        $file = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($file, $start, $end - $start));

        $this->assertStringContainsString('ToolRegistry', $body);
        $this->assertStringContainsString('toMcpShape', $body);
        $this->assertStringContainsString('json_encode', $body);
    }

    // ── Registration ─────────────────────────────────────────────────────────

    public function testAllAuxCommandsRegisteredInApplication(): void
    {
        $rc = new ReflectionClass(\Fw\Console\Application::class);
        $method = $rc->getMethod('registerBuiltinCommands');

        $file = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($file, $start, $end - $start));

        $this->assertStringContainsString('AuxListCommand', $body);
        $this->assertStringContainsString('AuxCallCommand', $body);
        $this->assertStringContainsString('AuxSchemaCommand', $body);
        $this->assertStringContainsString('ServeMcpCommand', $body);
    }
}
