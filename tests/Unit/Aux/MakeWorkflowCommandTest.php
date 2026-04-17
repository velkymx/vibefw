<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Console\Application as ConsoleApp;
use Fw\Console\Commands\MakeWorkflowCommand;
use Fw\Console\Input;
use Fw\Console\Output;
use PHPUnit\Framework\TestCase;

final class MakeWorkflowCommandTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/fw_makeworkflow_' . bin2hex(random_bytes(6));
        mkdir($this->tempRoot . '/app/Workflows', 0o755, true);
        mkdir($this->tempRoot . '/stubs/workflows', 0o755, true);

        copy(BASE_PATH . '/stubs/workflow.stub', $this->tempRoot . '/stubs/workflow.stub');
        copy(BASE_PATH . '/stubs/workflows/onboarding.stub', $this->tempRoot . '/stubs/workflows/onboarding.stub');
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempRoot);
    }

    public function testCommandConfiguresTemplateOption(): void
    {
        $cmd = $this->makeCommand(['CustomerFlow']);

        $options = $cmd->getOptions();
        $this->assertArrayHasKey('template', $options);
    }

    public function testDefaultGeneratesFromWorkflowStub(): void
    {
        $cmd = $this->makeCommand(['CustomerFlow']);

        $this->assertSame(0, $cmd->handle());

        $generated = file_get_contents($this->tempRoot . '/app/Workflows/CustomerFlowWorkflow.php');
        $this->assertStringContainsString('class CustomerFlowWorkflow extends WorkflowAction', $generated);
        $this->assertStringNotContainsString('CreateUser', $generated, 'default stub should not include onboarding-specific commands');
    }

    public function testTemplateOnboardingGeneratesFromOnboardingStub(): void
    {
        $cmd = $this->makeCommand(['CustomerOnboarding'], ['template' => 'onboarding']);

        $this->assertSame(0, $cmd->handle());

        $path = $this->tempRoot . '/app/Workflows/CustomerOnboardingWorkflow.php';
        $this->assertFileExists($path);

        $generated = file_get_contents($path);
        $this->assertStringContainsString('class CustomerOnboardingWorkflow extends WorkflowAction', $generated);
        $this->assertStringContainsString('CreateUser', $generated);
        $this->assertStringContainsString('SendWelcomeEmail', $generated);
        $this->assertStringContainsString('ProvisionDefaults', $generated);
    }

    public function testUnknownTemplateReturnsError(): void
    {
        $cmd = $this->makeCommand(['Whatever'], ['template' => 'nonexistent']);

        $this->assertSame(1, $cmd->handle());
        $this->assertFileDoesNotExist($this->tempRoot . '/app/Workflows/WhateverWorkflow.php');
    }

    public function testInvalidTemplateNameRejected(): void
    {
        $cmd = $this->makeCommand(['Whatever'], ['template' => '../etc/passwd']);

        $this->assertSame(1, $cmd->handle());
        $this->assertFileDoesNotExist($this->tempRoot . '/app/Workflows/WhateverWorkflow.php');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @param list<string> $args positional args (first = workflow name)
     * @param array<string, string> $options
     */
    private function makeCommand(array $args, array $options = []): MakeWorkflowCommand
    {
        $console = new ConsoleApp($this->tempRoot);
        $cmd = new MakeWorkflowCommand($console);
        $cmd->configure();
        $cmd->setOutput(new Output());

        $argv = ['fw', 'make:workflow', ...$args];
        foreach ($options as $key => $val) {
            $argv[] = "--{$key}={$val}";
        }
        $cmd->setInput(new Input($argv, $cmd->getArguments(), $cmd->getOptions()));

        return $cmd;
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = "$dir/$entry";
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
