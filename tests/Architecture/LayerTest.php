<?php

declare(strict_types=1);

namespace Fw\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Architecture tests to enforce layer boundaries and dependency rules.
 *
 * These tests prevent common architectural violations:
 * - Controllers accessing database directly (should use Models/Repositories)
 * - Models depending on Controllers
 * - Circular dependencies between layers
 * - Framework code depending on application code
 */
final class LayerTest extends TestCase
{
    private const BASE_PATH = __DIR__ . '/../../';

    /**
     * Controllers should not use PDO or Connection directly.
     * They should use Models, Repositories, or Services.
     */
    public function testControllersDoNotAccessDatabaseDirectly(): void
    {
        $violations = [];
        $controllerFiles = $this->getPhpFiles(self::BASE_PATH . 'app/Controllers');

        foreach ($controllerFiles as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            // Check for direct PDO usage
            if (preg_match('/\bPDO\b/', $content) && !str_contains($content, 'use PDO')) {
                if (preg_match('/new\s+PDO\b/', $content)) {
                    $violations[] = "$relativePath: Direct PDO instantiation";
                }
            }

            // Check for direct Connection usage
            if (preg_match('/Connection::getInstance|new\s+Connection/', $content)) {
                $violations[] = "$relativePath: Direct database Connection access";
            }

            // Check for raw SQL queries
            if (preg_match('/->query\s*\(\s*[\'"]SELECT|->exec\s*\(\s*[\'"]/', $content)) {
                $violations[] = "$relativePath: Raw SQL query in controller";
            }
        }

        $this->assertEmpty(
            $violations,
            "Controllers should not access database directly:\n" . implode("\n", $violations)
        );
    }

    /**
     * Models should not depend on Controllers.
     */
    public function testModelsDoNotDependOnControllers(): void
    {
        $violations = [];
        $modelFiles = $this->getPhpFiles(self::BASE_PATH . 'app/Models');

        foreach ($modelFiles as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            if (preg_match('/use\s+App\\\\Controllers\\\\/', $content)) {
                $violations[] = "$relativePath: Model imports Controller";
            }
        }

        $this->assertEmpty(
            $violations,
            "Models should not depend on Controllers:\n" . implode("\n", $violations)
        );
    }

    /**
     * Framework (src/) should not depend on application (app/) code.
     *
     * Exception: Auth layer can reference App\Models for policies (by design).
     */
    public function testFrameworkDoesNotDependOnApp(): void
    {
        $violations = [];
        $srcFiles = $this->getPhpFiles(self::BASE_PATH . 'src');

        // Allowed exceptions: Auth can reference App for policy resolution,
        // and code generators emit App namespace strings in generated output
        $allowedExceptions = [
            'src/Auth/Auth.php',
            'src/Auth/Gate.php',
            'src/Auth/Policy.php',
            'src/Auth/ApiToken.php',
            'src/Auth/TokenGuard.php',
            'src/Console/Commands/MakeResourceCommand.php',
        ];

        foreach ($srcFiles as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            // Skip allowed exceptions
            if (in_array($relativePath, $allowedExceptions, true)) {
                continue;
            }

            if (preg_match('/use\s+App\\\\/', $content)) {
                $violations[] = "$relativePath: Framework imports App namespace";
            }
        }

        $this->assertEmpty(
            $violations,
            "Framework (src/) should not depend on application (app/):\n" . implode("\n", $violations)
        );
    }

    /**
     * Views should not contain complex PHP logic.
     */
    public function testViewsDoNotContainComplexLogic(): void
    {
        $violations = [];
        $viewFiles = $this->getPhpFiles(self::BASE_PATH . 'app/Views');

        $forbiddenPatterns = [
            '/\bclass\s+\w+/' => 'Class definition',
            '/\bfunction\s+\w+\s*\(/' => 'Function definition',
            '/\bnamespace\s+/' => 'Namespace declaration',
            '/\bnew\s+PDO\b/' => 'PDO instantiation',
            '/\bquery\s*\(/' => 'Database query',
            '/\brequire_once\s+[\'"][^"\']+\.php[\'"]/' => 'PHP file include',
        ];

        foreach ($viewFiles as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            foreach ($forbiddenPatterns as $pattern => $description) {
                if (preg_match($pattern, $content)) {
                    $violations[] = "$relativePath: Contains $description";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Views should not contain complex PHP logic:\n" . implode("\n", $violations)
        );
    }

    /**
     * Middleware should not instantiate Models directly (use injection).
     */
    public function testMiddlewareUseDependencyInjection(): void
    {
        $violations = [];
        $middlewareFiles = $this->getPhpFiles(self::BASE_PATH . 'src/Middleware');

        foreach ($middlewareFiles as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            // Check for direct model instantiation (should be injected)
            if (preg_match('/new\s+[A-Z]\w+Model\b/', $content)) {
                $violations[] = "$relativePath: Direct model instantiation (use DI)";
            }
        }

        $this->assertEmpty(
            $violations,
            "Middleware should use dependency injection:\n" . implode("\n", $violations)
        );
    }

    /**
     * No circular dependencies between major namespaces.
     *
     * Allowed exceptions:
     * - Database <-> Model: ORM requires bidirectional relationship by design
     */
    public function testNoCircularDependencies(): void
    {
        $dependencyMap = [
            'Core' => [],
            'Database' => [],
            'Model' => [],
            'Auth' => [],
            'Cache' => [],
            'Queue' => [],
        ];

        // Allowed circular dependencies (by design)
        $allowedCircular = [
            ['Database', 'Model'], // ORM pattern requires this
            ['Core', 'Model'],     // HttpKernel resets Model static state; Model uses Core\Config, Core\Container
            ['Core', 'Auth'],      // HttpKernel resets Auth static state; Auth uses Core\RequestContext
        ];

        $srcPath = self::BASE_PATH . 'src';

        foreach (array_keys($dependencyMap) as $namespace) {
            $dir = $srcPath . '/' . $namespace;
            if (!is_dir($dir)) {
                continue;
            }

            $files = $this->getPhpFiles($dir);
            foreach ($files as $file) {
                $content = file_get_contents($file);

                foreach (array_keys($dependencyMap) as $otherNamespace) {
                    if ($namespace === $otherNamespace) {
                        continue;
                    }

                    if (preg_match('/use\s+Fw\\\\' . $otherNamespace . '\\\\/', $content)) {
                        $dependencyMap[$namespace][] = $otherNamespace;
                    }
                }
            }

            $dependencyMap[$namespace] = array_unique($dependencyMap[$namespace]);
        }

        // Check for circular dependencies
        $violations = [];
        foreach ($dependencyMap as $namespace => $dependencies) {
            foreach ($dependencies as $dep) {
                if (in_array($namespace, $dependencyMap[$dep] ?? [], true)) {
                    // Check if this is an allowed exception
                    $pair = [$namespace, $dep];
                    sort($pair);
                    $isAllowed = false;
                    foreach ($allowedCircular as $allowed) {
                        sort($allowed);
                        if ($pair === $allowed) {
                            $isAllowed = true;
                            break;
                        }
                    }
                    if (!$isAllowed) {
                        $violations[] = "Circular dependency: $namespace <-> $dep";
                    }
                }
            }
        }

        // Remove duplicates (A->B and B->A are the same circular dep)
        $violations = array_unique($violations);

        $this->assertEmpty(
            $violations,
            "Circular dependencies detected:\n" . implode("\n", $violations)
        );
    }

    /**
     * Get all PHP files in a directory recursively.
     *
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
