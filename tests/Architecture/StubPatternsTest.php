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

    // --- SPA Stub Tests ---

    #[Test]
    public function spaRequestStubsUseTypedRules(): void
    {
        $requestStubs = glob(self::STUBS_DIR . 'spa/app/Requests/*.stub');
        $this->assertNotEmpty($requestStubs, 'SPA request stubs should exist');

        foreach ($requestStubs as $stub) {
            $content = file_get_contents($stub);
            $this->assertStringContainsString(
                'function rules()',
                $content,
                basename($stub) . ' must define rules() method'
            );
            $this->assertDoesNotMatchRegularExpression(
                '/[\'"]required\|/',
                $content,
                basename($stub) . ' must not use string pipe validation rules'
            );
            $this->assertStringContainsString(
                'FormRequest',
                $content,
                basename($stub) . ' must extend FormRequest'
            );
        }
    }

    #[Test]
    public function spaControllerStubsUseMatchForOption(): void
    {
        $controllerStubs = array_merge(
            glob(self::STUBS_DIR . 'spa/app/Controllers/Api/*.stub'),
            glob(self::STUBS_DIR . 'spa/app/Controllers/Api/Auth/*.stub'),
        );
        $this->assertNotEmpty($controllerStubs, 'SPA controller stubs should exist');

        foreach ($controllerStubs as $stub) {
            $content = file_get_contents($stub);
            // If the stub uses first() or find(), it must use match()
            if (str_contains($content, '->first()') || str_contains($content, '::find(')) {
                $this->assertStringContainsString(
                    '->match(',
                    $content,
                    basename($stub) . ' must use match() for Option results'
                );
            }
        }
    }

    #[Test]
    public function spaControllerStubsReturnResponse(): void
    {
        $controllerStubs = array_merge(
            glob(self::STUBS_DIR . 'spa/app/Controllers/Api/*.stub'),
            glob(self::STUBS_DIR . 'spa/app/Controllers/Api/Auth/*.stub'),
        );

        foreach ($controllerStubs as $stub) {
            $content = file_get_contents($stub);
            $this->assertStringContainsString(
                'Response',
                $content,
                basename($stub) . ' must use Response return type'
            );
        }
    }

    #[Test]
    public function spaStubsHaveStrictTypes(): void
    {
        $phpStubs = array_merge(
            glob(self::STUBS_DIR . 'spa/app/Controllers/Api/*.stub'),
            glob(self::STUBS_DIR . 'spa/app/Controllers/Api/Auth/*.stub'),
            glob(self::STUBS_DIR . 'spa/app/Requests/*.stub'),
            glob(self::STUBS_DIR . 'spa/app/Models/*.stub'),
        );

        foreach ($phpStubs as $stub) {
            $content = file_get_contents($stub);
            $this->assertStringContainsString(
                'declare(strict_types=1)',
                $content,
                basename($stub) . ' must declare strict_types=1'
            );
        }
    }

    #[Test]
    public function spaUserModelHasFillable(): void
    {
        $content = file_get_contents(self::STUBS_DIR . 'spa/app/Models/User.php.stub');

        $this->assertStringContainsString('$fillable', $content);
        $this->assertStringNotContainsString('$guarded', $content);
    }

    #[Test]
    public function spaFrontendStubsExist(): void
    {
        $requiredStubs = [
            'src/main.ts',
            'src/App.vue',
            'src/router/index.ts',
            'src/layouts/MainLayout.vue',
            'src/views/Home.vue',
            'src/views/Dashboard.vue',
            'src/views/auth/Login.vue',
            'src/views/auth/Register.vue',
            'src/views/errors/NotFound.vue',
            'src/views/profile/Profile.vue',
            'src/types/api.ts',
            'src/stores/auth.ts',
            'src/lib/axios.ts',
        ];

        foreach ($requiredStubs as $stub) {
            $this->assertFileExists(
                self::STUBS_DIR . 'spa/' . $stub . '.stub',
                "SPA frontend stub missing: {$stub}.stub"
            );
        }
    }

    #[Test]
    public function spaFrontendStubsUseAxiosInstance(): void
    {
        $viewStubs = $this->allSpaVueStubs();
        $this->assertNotEmpty($viewStubs, 'SPA Vue stubs should exist');

        foreach ($viewStubs as $stub) {
            $content = file_get_contents($stub);
            // If it makes API calls, it should import from @/lib/axios, not bare 'axios'
            if (str_contains($content, 'axios.get') || str_contains($content, 'axios.post')
                || str_contains($content, 'axios.put') || str_contains($content, 'axios.delete')) {
                $this->assertStringContainsString(
                    '@/lib/axios',
                    $content,
                    basename($stub) . ' must import from @/lib/axios, not bare axios'
                );
            }
        }
    }

    #[Test]
    public function spaFrontendStubsUseAuthStore(): void
    {
        $viewStubs = $this->allSpaVueStubs();
        $this->assertNotEmpty($viewStubs, 'SPA Vue stubs should exist');

        foreach ($viewStubs as $stub) {
            $content = file_get_contents($stub);
            // No direct localStorage.getItem('token') in view stubs
            $this->assertStringNotContainsString(
                "localStorage.getItem('token')",
                $content,
                basename($stub) . ' must not read token from localStorage directly — use auth store'
            );
        }
    }

    /** @return list<string> */
    private function allSpaVueStubs(): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::STUBS_DIR . 'spa/src/', \FilesystemIterator::SKIP_DOTS)
        );
        $stubs = [];
        foreach ($iterator as $file) {
            if (str_ends_with($file->getFilename(), '.vue.stub')) {
                $stubs[] = $file->getPathname();
            }
        }
        return $stubs;
    }

    #[Test]
    public function spaStubsHaveNoVerboseDocblocks(): void
    {
        $phpStubs = array_merge(
            glob(self::STUBS_DIR . 'spa/app/Controllers/Api/*.stub'),
            glob(self::STUBS_DIR . 'spa/app/Controllers/Api/Auth/*.stub'),
            glob(self::STUBS_DIR . 'spa/app/Models/*.stub'),
        );
        $this->assertNotEmpty($phpStubs, 'SPA PHP stubs should exist');

        foreach ($phpStubs as $stub) {
            $content = file_get_contents($stub);
            preg_match_all('#/\*\*.*?\*/#s', $content, $matches);
            foreach ($matches[0] as $docblock) {
                $lineCount = substr_count($docblock, "\n") + 1;
                $this->assertLessThanOrEqual(
                    3,
                    $lineCount,
                    basename($stub) . " has a verbose docblock ($lineCount lines). V3 stubs should be clean."
                );
            }
        }
    }
}
