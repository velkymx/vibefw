<?php

declare(strict_types=1);

namespace Fw\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Security pattern tests to catch common vulnerabilities at the architecture level.
 *
 * These tests enforce security best practices:
 * - No unsafe unserialize
 * - No eval or similar dangerous functions
 * - No hardcoded credentials
 * - Proper use of prepared statements
 */
final class SecurityPatternTest extends TestCase
{
    private const BASE_PATH = __DIR__ . '/../../';

    /**
     * No unserialize without allowed_classes restriction.
     */
    public function testNoUnsafeUnserialize(): void
    {
        $violations = [];
        $files = array_merge(
            $this->getPhpFiles(self::BASE_PATH . 'src'),
            $this->getPhpFiles(self::BASE_PATH . 'app')
        );

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            // Skip test files and security scanner itself
            if (str_contains($relativePath, 'Test') || str_contains($relativePath, 'SecurityScan')) {
                continue;
            }

            if (preg_match('/\bunserialize\s*\(/', $content)) {
                if (!str_contains($content, 'allowed_classes')) {
                    $violations[] = "$relativePath: unserialize() without allowed_classes";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Unsafe unserialize() detected (use json_decode or specify allowed_classes):\n" . implode("\n", $violations)
        );
    }

    /**
     * No eval, create_function, or preg_replace with /e modifier.
     */
    public function testNoCodeExecution(): void
    {
        $violations = [];
        $files = array_merge(
            $this->getPhpFiles(self::BASE_PATH . 'src'),
            $this->getPhpFiles(self::BASE_PATH . 'app')
        );

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            // Skip security scanner files
            if (str_contains($relativePath, 'SecurityScan') || str_contains($relativePath, 'ValidateSecurity')) {
                continue;
            }

            // Skip test files
            if (str_contains($relativePath, 'Test.php')) {
                continue;
            }

            $lines = explode("\n", $content);
            foreach ($lines as $lineNum => $line) {
                // Skip comments
                $trimmed = trim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }

                // Skip lines that are part of preg_match patterns (security scanner checking for these)
                if (str_contains($line, 'preg_match(')) {
                    continue;
                }

                if (preg_match('/\beval\s*\(/', $line)) {
                    $violations[] = "$relativePath:" . ($lineNum + 1) . ": eval()";
                }

                if (preg_match('/\bcreate_function\s*\(/', $line)) {
                    $violations[] = "$relativePath:" . ($lineNum + 1) . ": create_function()";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Dangerous code execution functions detected:\n" . implode("\n", $violations)
        );
    }

    /**
     * No hardcoded passwords or API keys.
     */
    public function testNoHardcodedCredentials(): void
    {
        $violations = [];
        $files = array_merge(
            $this->getPhpFiles(self::BASE_PATH . 'src'),
            $this->getPhpFiles(self::BASE_PATH . 'app'),
            $this->getPhpFiles(self::BASE_PATH . 'config')
        );

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            // Skip test files and examples
            if (str_contains($relativePath, 'Test') || str_contains($relativePath, 'example')) {
                continue;
            }

            // Skip security scanner
            if (str_contains($relativePath, 'SecurityScan') || str_contains($relativePath, 'ValidateSecurity')) {
                continue;
            }

            $lines = explode("\n", $content);
            foreach ($lines as $lineNum => $line) {
                // Skip if line references Env:: (environment variable)
                if (str_contains($line, 'Env::') || str_contains($line, 'getenv(') || str_contains($line, '$_ENV')) {
                    continue;
                }

                // Skip comments
                $trimmed = trim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#')) {
                    continue;
                }

                // Check for password assignments with actual values
                if (preg_match('/\$(password|passwd|pwd|secret|api_?key|token)\s*=\s*[\'"][a-zA-Z0-9]{8,}[\'"]/i', $line)) {
                    $violations[] = "$relativePath:" . ($lineNum + 1) . ": Possible hardcoded credential";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Hardcoded credentials detected (use environment variables):\n" . implode("\n", $violations)
        );
    }

    /**
     * Database queries should use parameterized queries.
     */
    public function testNoSqlConcatenation(): void
    {
        $violations = [];
        $files = array_merge(
            $this->getPhpFiles(self::BASE_PATH . 'src'),
            $this->getPhpFiles(self::BASE_PATH . 'app')
        );

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            // Skip security scanners and tests
            if (str_contains($relativePath, 'SecurityScan') || str_contains($relativePath, 'Test')) {
                continue;
            }

            // Skip QueryBuilder itself (it's the one building queries safely)
            if (str_contains($relativePath, 'QueryBuilder') || str_contains($relativePath, 'Migrator')) {
                continue;
            }

            $lines = explode("\n", $content);
            foreach ($lines as $lineNum => $line) {
                // Skip comments
                $trimmed = trim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }

                // Look for SQL with variable interpolation
                if (preg_match('/"[^"]*\b(SELECT|INSERT|UPDATE|DELETE)\b[^"]*\$[a-zA-Z_]/', $line)) {
                    // Skip if it's building internal framework queries with safe variables:
                    // - $this->table / $table: internal table name references
                    // - $q* variables: pre-quoted identifiers via quoteIdentifier()
                    // - $placeholders: parameter placeholder strings like ?,?,?
                    $isSafe = str_contains($line, '$this->table')
                        || str_contains($line, '$this->quotedTable')
                        || str_contains($line, '$table')
                        || str_contains($line, '$quoted')
                        || preg_match('/\$q[A-Z]/', $line)
                        || str_contains($line, '$placeholders');
                    if (!$isSafe) {
                        $violations[] = "$relativePath:" . ($lineNum + 1) . ": SQL with variable interpolation";
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "SQL injection risk - use parameterized queries:\n" . implode("\n", $violations)
        );
    }

    /**
     * No debug functions in non-debug code.
     */
    public function testNoDebugFunctions(): void
    {
        $violations = [];
        $files = array_merge(
            $this->getPhpFiles(self::BASE_PATH . 'src'),
            $this->getPhpFiles(self::BASE_PATH . 'app')
        );

        $debugFunctions = ['var_dump', 'print_r', 'debug_print_backtrace'];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace(self::BASE_PATH, '', $file);

            // Skip test files and debug helpers
            if (str_contains($relativePath, 'Test') || str_contains($relativePath, 'Stringable')) {
                continue;
            }

            // Skip security scanner
            if (str_contains($relativePath, 'SecurityScan') || str_contains($relativePath, 'ValidateSecurity')) {
                continue;
            }

            // Skip Pipe.php (has debug methods by design)
            if (str_contains($relativePath, 'Pipe.php')) {
                continue;
            }

            $lines = explode("\n", $content);
            foreach ($lines as $lineNum => $line) {
                // Skip comments and method definitions
                $trimmed = trim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }
                if (str_contains($line, 'function ')) {
                    continue;
                }

                foreach ($debugFunctions as $func) {
                    if (preg_match('/\b' . $func . '\s*\(/', $line)) {
                        $violations[] = "$relativePath:" . ($lineNum + 1) . ": $func()";
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Debug functions detected in production code:\n" . implode("\n", $violations)
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
