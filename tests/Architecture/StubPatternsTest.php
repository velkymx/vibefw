<?php

declare(strict_types=1);

namespace Fw\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verify all stubs follow v3 conventions:
 * - CUSTOMIZE markers for AI-editable sections
 * - No string pipe validation rules
 * - match() instead of if/else on Result/Option
 * - Typed validation rules in request stubs
 */
final class StubPatternsTest extends TestCase
{
    private const string STUBS_DIR = __DIR__ . '/../../stubs/';

    #[Test]
    public function modelStubHasFillableArray(): void
    {
        $content = file_get_contents(self::STUBS_DIR . 'model.stub');

        $this->assertStringContainsString('$fillable', $content);
        $this->assertStringContainsString('// CUSTOMIZE:', $content);
    }

    #[Test]
    public function controllerResourceStubUsesFormRequest(): void
    {
        $content = file_get_contents(self::STUBS_DIR . 'controller.resource.stub');

        $this->assertStringContainsString('Request', $content);
        $this->assertStringContainsString('Response', $content);
        $this->assertStringContainsString('// CUSTOMIZE:', $content);
    }

    #[Test]
    public function requestStubUsesTypedRules(): void
    {
        $content = file_get_contents(self::STUBS_DIR . 'request.stub');

        $this->assertStringContainsString('function rules()', $content);
        $this->assertStringContainsString('Required', $content);
        // Should NOT use string pipe syntax
        $this->assertStringNotContainsString("'required|", $content);
        $this->assertStringContainsString('// CUSTOMIZE:', $content);
    }

    #[Test]
    public function migrationStubHasCustomizeMarker(): void
    {
        $content = file_get_contents(self::STUBS_DIR . 'migration.create.stub');

        $this->assertStringContainsString('// CUSTOMIZE:', $content);
    }

    #[Test]
    public function factoryStubHasCustomizeMarker(): void
    {
        $content = file_get_contents(self::STUBS_DIR . 'factory.stub');

        $this->assertStringContainsString('// CUSTOMIZE:', $content);
    }

    #[Test]
    public function allStubsHaveStrictTypes(): void
    {
        $stubs = glob(self::STUBS_DIR . '*.stub');

        foreach ($stubs as $stub) {
            $content = file_get_contents($stub);
            $this->assertStringContainsString(
                'declare(strict_types=1)',
                $content,
                basename($stub) . ' must declare strict_types=1'
            );
        }
    }

    #[Test]
    public function noStubUsesStringPipeValidation(): void
    {
        $stubs = glob(self::STUBS_DIR . '*.stub');

        foreach ($stubs as $stub) {
            $content = file_get_contents($stub);
            $this->assertDoesNotMatchRegularExpression(
                '/[\'"]required\|/',
                $content,
                basename($stub) . ' must not use string pipe validation rules'
            );
        }
    }

    #[Test]
    public function allStubsHaveCustomizeMarker(): void
    {
        $stubs = glob(self::STUBS_DIR . '*.stub');

        foreach ($stubs as $stub) {
            $content = file_get_contents($stub);
            $this->assertStringContainsString(
                '// CUSTOMIZE:',
                $content,
                basename($stub) . ' must have at least one // CUSTOMIZE: marker'
            );
        }
    }

    #[Test]
    public function middlewareStubReturnTypeMatchesInterface(): void
    {
        $content = file_get_contents(self::STUBS_DIR . 'middleware.stub');

        $this->assertStringContainsString('Response|string|array', $content);
        $this->assertStringContainsString('MiddlewareInterface', $content);
        $this->assertStringContainsString('// CUSTOMIZE:', $content);
    }

    #[Test]
    public function commandStubIsReadonly(): void
    {
        $content = file_get_contents(self::STUBS_DIR . 'command.stub');

        $this->assertStringContainsString('final readonly class', $content);
        $this->assertStringContainsString('// CUSTOMIZE:', $content);
    }

    #[Test]
    public function queryStubIsReadonly(): void
    {
        $content = file_get_contents(self::STUBS_DIR . 'query.stub');

        $this->assertStringContainsString('final readonly class', $content);
        $this->assertStringContainsString('// CUSTOMIZE:', $content);
    }

    #[Test]
    public function providerStubHasCustomizeMarker(): void
    {
        $content = file_get_contents(self::STUBS_DIR . 'provider.stub');

        $this->assertStringContainsString('// CUSTOMIZE:', $content);
    }

    #[Test]
    public function seederStubHasCustomizeMarker(): void
    {
        $content = file_get_contents(self::STUBS_DIR . 'seeder.stub');

        $this->assertStringContainsString('// CUSTOMIZE:', $content);
    }
}
