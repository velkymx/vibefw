<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Console\Commands\AuxDoctorCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AuxDoctorCommandTest extends TestCase
{
    public function testCommandExists(): void
    {
        $this->assertTrue(class_exists(AuxDoctorCommand::class));
    }

    public function testCommandHasCorrectName(): void
    {
        $rc = new ReflectionClass(AuxDoctorCommand::class);
        $this->assertSame('aux:doctor', $rc->getProperty('name')->getDefaultValue());
    }

    public function testHandleResolvesAuxServices(): void
    {
        $rc = new ReflectionClass(AuxDoctorCommand::class);
        $method = $rc->getMethod('handle');

        $file = file($method->getFileName());
        $body = implode('', array_slice(
            $file,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $this->assertStringContainsString('ToolRegistry', $body);
        $this->assertStringContainsString('FeatureIndex', $body);
        $this->assertStringContainsString('AuxStats', $body);
        $this->assertStringContainsString('AuxDoctor', $body);
        $this->assertStringContainsString('audit()', $body);
        $this->assertStringContainsString('table(', $body);
    }

    public function testRegisteredInConsoleApplication(): void
    {
        $rc = new ReflectionClass(\Fw\Console\Application::class);
        $method = $rc->getMethod('registerBuiltinCommands');

        $file = file($method->getFileName());
        $body = implode('', array_slice(
            $file,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $this->assertStringContainsString('AuxDoctorCommand', $body);
    }
}
