<?php

declare(strict_types=1);

namespace Fw\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Convention enforcement tests for v3.
 *
 * Ensures all application code follows the single-path v3 conventions.
 * These scan app/ and config/ for violations that weak AI models
 * commonly produce.
 */
final class ConventionTest extends TestCase
{
    private const string BASE_PATH = __DIR__ . '/../../';

    #[Test]
    public function allPhpFilesHaveStrictTypes(): void
    {
        $violations = [];
        $files = array_merge(
            $this->getPhpFiles(self::BASE_PATH . 'src'),
            $this->getPhpFiles(self::BASE_PATH . 'app/Controllers'),
            $this->getPhpFiles(self::BASE_PATH . 'app/Models'),
            $this->getPhpFiles(self::BASE_PATH . 'app/Requests'),
        );

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            if (!str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = $relativePath;
            }
        }

        $this->assertEmpty(
            $violations,
            "All PHP files must declare strict_types=1:\n" . implode("\n", $violations)
        );
    }

    #[Test]
    public function allControllersExtendBaseController(): void
    {
        $violations = [];
        $files = $this->getPhpFiles(self::BASE_PATH . 'app/Controllers');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            // Must extend Controller
            if (preg_match('/class\s+\w+/', $content) && !str_contains($content, 'extends Controller')) {
                $violations[] = "$relativePath: Does not extend Fw\\Core\\Controller";
            }
        }

        $this->assertEmpty(
            $violations,
            "All controllers must extend Fw\\Core\\Controller:\n" . implode("\n", $violations)
        );
    }

    #[Test]
    public function controllerMethodsReturnResponse(): void
    {
        $violations = [];
        $files = $this->getPhpFiles(self::BASE_PATH . 'app/Controllers');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            // Find public methods that are route handlers (accept Request)
            preg_match_all(
                '/public\s+function\s+(\w+)\s*\([^)]*Request[^)]*\)(?:\s*:\s*(\w+))?/',
                $content,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $method = $match[1];
                $returnType = $match[2] ?? '';

                if ($returnType !== 'Response') {
                    $violations[] = "$relativePath::{$method}(): Missing or non-Response return type (got: " . ($returnType ?: 'none') . ")";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Controller methods accepting Request must return Response:\n" . implode("\n", $violations)
        );
    }

    #[Test]
    public function allModelsExtendBaseModel(): void
    {
        $violations = [];
        $files = $this->getPhpFiles(self::BASE_PATH . 'app/Models');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            if (preg_match('/class\s+\w+/', $content) && !str_contains($content, 'extends Model')) {
                $violations[] = "$relativePath: Does not extend Fw\\Model\\Model";
            }
        }

        $this->assertEmpty(
            $violations,
            "All models must extend Fw\\Model\\Model:\n" . implode("\n", $violations)
        );
    }

    #[Test]
    public function allModelsDeclareFillable(): void
    {
        $violations = [];
        $files = $this->getPhpFiles(self::BASE_PATH . 'app/Models');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            // Skip abstract classes
            if (str_contains($content, 'abstract class')) {
                continue;
            }

            if (!str_contains($content, '$fillable')) {
                $violations[] = "$relativePath: Missing \$fillable declaration";
            }
        }

        $this->assertEmpty(
            $violations,
            "All models must declare \$fillable:\n" . implode("\n", $violations)
        );
    }

    #[Test]
    public function noModelUsesGuarded(): void
    {
        $violations = [];
        $files = $this->getPhpFiles(self::BASE_PATH . 'app/Models');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            if (preg_match('/\$guarded\s*=/', $content)) {
                $violations[] = "$relativePath: Uses \$guarded (use \$fillable instead)";
            }
        }

        $this->assertEmpty(
            $violations,
            "Models must not use \$guarded (use \$fillable only):\n" . implode("\n", $violations)
        );
    }

    #[Test]
    public function noStringPipeValidationInAppCode(): void
    {
        $violations = [];
        $files = array_merge(
            $this->getPhpFiles(self::BASE_PATH . 'app/Controllers'),
            $this->getPhpFiles(self::BASE_PATH . 'app/Requests'),
        );

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            // Check for string pipe rules like 'required|min:3'
            if (preg_match("/['\"]required\|/", $content)) {
                $violations[] = "$relativePath: Uses string pipe validation rules (use typed Rule objects)";
            }
        }

        $this->assertEmpty(
            $violations,
            "String pipe validation rules are removed in v3. Use typed Rule objects:\n" . implode("\n", $violations)
        );
    }

    #[Test]
    public function noRemovedControllerMethodsInAppCode(): void
    {
        $removedMethods = [
            'dbQuery', 'dbQueryOne', 'validate', 'checkRule',
            'abort', 'input', 'only', 'except', 'has',
        ];

        $violations = [];
        $files = $this->getPhpFiles(self::BASE_PATH . 'app/Controllers');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            foreach ($removedMethods as $method) {
                // Match $this->method( calls
                if (preg_match('/\$this->' . $method . '\s*\(/', $content)) {
                    $violations[] = "$relativePath: Uses removed method \$this->{$method}()";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Removed v2 Controller methods detected. Use FormRequest for validation:\n" . implode("\n", $violations)
        );
    }

    #[Test]
    public function noRemovedResultOptionMethodsInAppCode(): void
    {
        $violations = [];
        $files = array_merge(
            $this->getPhpFiles(self::BASE_PATH . 'app'),
        );

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            if (preg_match('/->unwrap\(\)/', $content)) {
                $violations[] = "$relativePath: Uses removed ->unwrap() (use ->match() or ->unwrapOr())";
            }
            if (preg_match('/->getValue\(\)/', $content)) {
                $violations[] = "$relativePath: Uses removed ->getValue() (use ->match())";
            }
            if (preg_match('/->getError\(\)/', $content)) {
                $violations[] = "$relativePath: Uses removed ->getError() (use ->match())";
            }
        }

        $this->assertEmpty(
            $violations,
            "Removed Result/Option methods detected:\n" . implode("\n", $violations)
        );
    }

    #[Test]
    public function allFormRequestsImplementRulesMethod(): void
    {
        $violations = [];
        $files = $this->getPhpFiles(self::BASE_PATH . 'app/Requests');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            if (str_contains($content, 'extends FormRequest') && !str_contains($content, 'function rules()')) {
                $violations[] = "$relativePath: FormRequest missing rules() method";
            }
        }

        $this->assertEmpty(
            $violations,
            "All FormRequests must implement rules():\n" . implode("\n", $violations)
        );
    }

    #[Test]
    public function routesUseControllerArraySyntax(): void
    {
        $routesFile = self::BASE_PATH . 'config/routes.php';

        if (!file_exists($routesFile)) {
            $this->markTestSkipped('No routes file found');
        }

        $content = file_get_contents($routesFile);

        // Should not contain inline closure handlers
        $this->assertDoesNotMatchRegularExpression(
            '/\$router->(get|post|put|patch|delete)\s*\([^,]+,\s*function\s*\(/',
            $content,
            'Routes must use [Controller::class, \'method\'] syntax, not closures'
        );
    }

    #[Test]
    public function routesUseTypedMiddleware(): void
    {
        $routesFile = self::BASE_PATH . 'config/routes.php';

        if (!file_exists($routesFile)) {
            $this->markTestSkipped('No routes file found');
        }

        $content = file_get_contents($routesFile);

        // Should not contain plain string middleware like 'auth' or 'auth:api'
        $this->assertDoesNotMatchRegularExpression(
            "/->middleware\s*\(\s*['\"][a-z]/",
            $content,
            'Routes must use class-string middleware (e.g. AuthMiddleware::class), not plain strings'
        );
    }

    /**
     * @return array<string>
     */
    private function getPhpFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory)
        );
        $phpFiles = new RegexIterator($iterator, '/\.php$/');

        $files = [];
        foreach ($phpFiles as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }
}
