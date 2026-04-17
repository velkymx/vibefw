<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Console\Commands\AuxStatsCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AuxStatsCommandTest extends TestCase
{
    public function testCommandExists(): void
    {
        $this->assertTrue(class_exists(AuxStatsCommand::class));
    }

    public function testCommandHasCorrectName(): void
    {
        $rc = new ReflectionClass(AuxStatsCommand::class);
        $prop = $rc->getProperty('name');
        $this->assertSame('aux:stats', $prop->getDefaultValue());
    }

    public function testHandleResolvesRegistryAndStats(): void
    {
        $rc = new ReflectionClass(AuxStatsCommand::class);
        $body = $this->methodBody($rc->getMethod('handle'));

        $this->assertStringContainsString('ToolRegistry', $body);
        $this->assertStringContainsString('AuxStats', $body);
    }

    public function testHandleRendersTableWithBudgetAndActuals(): void
    {
        $rc = new ReflectionClass(AuxStatsCommand::class);
        $body = $this->methodBody($rc->getMethod('handle'));

        $this->assertStringContainsString('->table(', $body);
        $this->assertStringContainsString('estimated', $body);
        $this->assertStringContainsString('avg_duration_ms', $body);
        $this->assertStringContainsString('error_count', $body);
    }

    public function testCommandRegisteredInApplication(): void
    {
        $rc = new ReflectionClass(\Fw\Console\Application::class);
        $body = $this->methodBody($rc->getMethod('registerBuiltinCommands'));

        $this->assertStringContainsString('AuxStatsCommand', $body);
    }

    private function methodBody(ReflectionMethod $method): string
    {
        $file = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        return implode('', array_slice($file, $start, $end - $start));
    }
}
