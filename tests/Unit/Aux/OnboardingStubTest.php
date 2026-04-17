<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use PHPUnit\Framework\TestCase;

final class OnboardingStubTest extends TestCase
{
    private const STUB_PATH = __DIR__ . '/../../../stubs/workflows/onboarding.stub';

    public function testStubFileExists(): void
    {
        $this->assertFileExists(self::STUB_PATH);
    }

    public function testStubDeclaresStrictTypes(): void
    {
        $content = file_get_contents(self::STUB_PATH);

        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    public function testStubExtendsWorkflowAction(): void
    {
        $content = file_get_contents(self::STUB_PATH);

        $this->assertStringContainsString('extends WorkflowAction', $content);
        $this->assertStringContainsString('use Fw\\Aux\\WorkflowAction;', $content);
        $this->assertStringContainsString('use Fw\\Aux\\WorkflowResult;', $content);
    }

    public function testStubUsesClassNamePlaceholder(): void
    {
        $content = file_get_contents(self::STUB_PATH);

        $this->assertStringContainsString('{{className}}', $content);
    }

    public function testStubHasCustomizeMarkers(): void
    {
        $content = file_get_contents(self::STUB_PATH);

        $this->assertGreaterThanOrEqual(
            2,
            substr_count($content, '// CUSTOMIZE:'),
            'onboarding.stub should have multiple CUSTOMIZE markers (one per step)',
        );
    }

    public function testStubShowsMultiStepDispatches(): void
    {
        $content = file_get_contents(self::STUB_PATH);

        $this->assertGreaterThanOrEqual(
            3,
            substr_count($content, '$this->dispatch('),
            'onboarding.stub must show 3+ dispatch calls (create user, welcome email, provision defaults)',
        );
    }

    public function testStubUsesMatchOnResult(): void
    {
        $content = file_get_contents(self::STUB_PATH);

        $this->assertStringContainsString('->match(', $content, 'stub must use match() on Result');
        $this->assertStringContainsString('ok:', $content);
        $this->assertStringContainsString('err:', $content);
    }

    public function testStubReturnsWorkflowResult(): void
    {
        $content = file_get_contents(self::STUB_PATH);

        $this->assertStringContainsString('WorkflowResult::', $content);
        $this->assertStringContainsString('completed', $content);
        $this->assertStringContainsString('failed', $content);
    }

    public function testStubNamespacedInAppWorkflows(): void
    {
        $content = file_get_contents(self::STUB_PATH);

        $this->assertStringContainsString('namespace App\\Workflows;', $content);
    }

    public function testStubHasNoPipeValidation(): void
    {
        $content = file_get_contents(self::STUB_PATH);

        $this->assertDoesNotMatchRegularExpression('/[\'"]required\|/', $content);
    }
}
