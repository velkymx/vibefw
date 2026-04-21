<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use FilesystemIterator;
use Fw\Console\Application;
use Fw\Console\Command;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Single entry point for all convention and quality checks.
 */
final class CheckCommand extends Command
{
    protected string $name = 'check';

    protected string $description = 'Run convention checks, architecture tests, and static analysis';

    private int $issueCount = 0;

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function handle(): int
    {
        $basePath = $this->app->getBasePath();

        $this->info('Running checks...');
        $this->newLine();

        // 1. Convention checks (inline — fast, no external tool needed)
        $this->line($this->output->color('[1/3] Convention checks', 'yellow'));
        $this->runConventionChecks($basePath);
        $this->newLine();

        // 2. Architecture tests
        $this->line($this->output->color('[2/3] Architecture tests', 'yellow'));
        $this->runArchitectureTests($basePath);
        $this->newLine();

        // 3. PHPStan
        $this->line($this->output->color('[3/3] PHPStan static analysis', 'yellow'));
        $this->runPhpStan($basePath);
        $this->newLine();

        // Summary
        $this->line(str_repeat('=', 50));
        if ($this->issueCount > 0) {
            $this->error("{$this->issueCount} issue(s) found. Auto-fix what you can with:");
            $this->line('  php fw fix');
            return 1;
        }

        $this->success('All checks passed!');
        return 0;
    }

    private function runConventionChecks(string $basePath): void
    {
        $checks = [
            'strict_types' => $this->checkStrictTypes($basePath),
            'no_guarded' => $this->checkNoGuarded($basePath),
            'fillable_declared' => $this->checkFillableDeclared($basePath),
            'no_string_pipe_validation' => $this->checkNoStringPipeValidation($basePath),
            'controllers_return_response' => $this->checkControllersReturnResponse($basePath),
            'no_closure_routes' => $this->checkNoClosureRoutes($basePath),
        ];

        $passed = 0;
        $failed = 0;

        foreach ($checks as $name => $issues) {
            if (empty($issues)) {
                $passed++;
            } else {
                $failed++;
                foreach ($issues as $issue) {
                    $this->line('  ' . $this->output->color('✗', 'red') . " {$issue}");
                }
            }
        }

        if ($failed === 0) {
            $this->success("  Convention checks: {$passed}/{$passed} passed");
        } else {
            $this->issueCount += $failed;
            $this->line("  {$passed} passed, {$failed} failed");
        }
    }

    /**
     * @return array<string>
     */
    private function checkStrictTypes(string $basePath): array
    {
        $issues = [];
        $dirs = ['src', 'app/Controllers', 'app/Models', 'app/Requests'];

        foreach ($dirs as $dir) {
            $path = $basePath . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }

            foreach ($this->phpFiles($path) as $file) {
                $content = file_get_contents($file);
                if ($content !== false && !str_contains($content, 'declare(strict_types=1)')) {
                    $relative = str_replace($basePath . '/', '', $file);
                    $issues[] = "{$relative}: missing declare(strict_types=1)";
                }
            }
        }

        return $issues;
    }

    /**
     * @return array<string>
     */
    private function checkNoGuarded(string $basePath): array
    {
        $issues = [];
        $path = $basePath . '/app/Models';
        if (!is_dir($path)) {
            return [];
        }

        foreach ($this->phpFiles($path) as $file) {
            $content = file_get_contents($file);
            if ($content !== false && preg_match('/\$guarded\s*=/', $content)) {
                $relative = str_replace($basePath . '/', '', $file);
                $issues[] = "{$relative}: uses \$guarded (v3 requires \$fillable only). Fix: replace with \$fillable";
            }
        }

        return $issues;
    }

    /**
     * @return array<string>
     */
    private function checkFillableDeclared(string $basePath): array
    {
        $issues = [];
        $path = $basePath . '/app/Models';
        if (!is_dir($path)) {
            return [];
        }

        foreach ($this->phpFiles($path) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            if (str_contains($content, 'extends Model') && !str_contains($content, '$fillable')) {
                $relative = str_replace($basePath . '/', '', $file);
                $className = pathinfo($file, PATHINFO_FILENAME);
                $issues[] = "{$relative}: Model missing \$fillable. Fix: php fw add:field {$className} name string";
            }
        }

        return $issues;
    }

    /**
     * @return array<string>
     */
    private function checkNoStringPipeValidation(string $basePath): array
    {
        $issues = [];
        $dirs = ['app/Controllers', 'app/Requests'];

        foreach ($dirs as $dir) {
            $path = $basePath . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }

            foreach ($this->phpFiles($path) as $file) {
                $content = file_get_contents($file);
                if ($content !== false && preg_match("/['\"][a-z]+\|[a-z]+/", $content)) {
                    $relative = str_replace($basePath . '/', '', $file);
                    $issues[] = "{$relative}: uses string pipe validation (v3 requires typed Rule objects). Fix: php fw fix";
                }
            }
        }

        return $issues;
    }

    /**
     * @return array<string>
     */
    private function checkControllersReturnResponse(string $basePath): array
    {
        $issues = [];
        $path = $basePath . '/app/Controllers';
        if (!is_dir($path)) {
            return [];
        }

        foreach ($this->phpFiles($path) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // Check for public methods with Request param that don't declare Response return type
            if (str_contains($content, 'extends Controller') &&
                preg_match('/public\s+function\s+\w+\s*\([^)]*Request/', $content) &&
                !str_contains($content, ': Response')) {
                $relative = str_replace($basePath . '/', '', $file);
                $issues[] = "{$relative}: controller methods should declare Response return type";
            }
        }

        return $issues;
    }

    private function runArchitectureTests(string $basePath): void
    {
        $phpunit = $basePath . '/vendor/bin/phpunit';
        if (!file_exists($phpunit)) {
            $this->comment('  PHPUnit not installed, skipping architecture tests');
            return;
        }

        $archTestDir = $basePath . '/tests/Architecture';
        if (!is_dir($archTestDir)) {
            $this->comment('  No architecture tests found');
            return;
        }

        $command = escapeshellarg($phpunit) . ' --testsuite=architecture --no-progress 2>&1';
        $output = [];
        exec($command, $output, $exitCode);

        $fullOutput = implode("\n", $output);
        $hasFailures = str_contains($fullOutput, 'FAILURES!') || str_contains($fullOutput, 'ERRORS!');

        if (!$hasFailures) {
            // Extract test count from output
            if (preg_match('/Tests:\s*(\d+)/', $fullOutput, $matches)) {
                $this->success("  Architecture tests passed ({$matches[1]} tests)");
            } else {
                $this->success('  Architecture tests passed');
            }
        } else {
            $this->issueCount++;
            if (preg_match('/FAILURES!.*$/m', $fullOutput, $matches)) {
                $this->line('  ' . $this->output->color('✗', 'red') . ' ' . $matches[0]);
            }
            $this->line('  Run for details: php fw test --testsuite=architecture');
        }
    }

    private function runPhpStan(string $basePath): void
    {
        $phpstan = $basePath . '/vendor/bin/phpstan';
        if (!file_exists($phpstan)) {
            $this->comment('  PHPStan not installed, skipping');
            return;
        }

        $command = escapeshellarg($phpstan) . ' analyse --no-progress --memory-limit=512M 2>&1';
        $output = [];
        exec($command, $output, $exitCode);

        if ($exitCode === 0) {
            $this->success('  PHPStan: no errors');
        } else {
            $fullOutput = implode("\n", $output);
            // Count errors
            if (preg_match('/Found (\d+) error/', $fullOutput, $matches)) {
                $count = (int) $matches[1];
                $this->issueCount += $count;
                $this->line('  ' . $this->output->color("✗ PHPStan: {$count} error(s)", 'red'));
            } else {
                $this->issueCount++;
                $this->line('  ' . $this->output->color('✗ PHPStan failed', 'red'));
            }
            $this->line('  Run for details: php vendor/bin/phpstan analyse');
        }
    }

    /**
     * @return array<string>
     */
    private function checkNoClosureRoutes(string $basePath): array
    {
        $routesFile = $basePath . '/config/routes.php';
        if (!file_exists($routesFile)) {
            return [];
        }

        $content = file_get_contents($routesFile);
        if ($content === false) {
            return [];
        }

        $issues = [];

        // Match closure patterns: fn() =>, fn () =>, function () {, function() {
        if (preg_match_all('/\$router->(get|post|put|patch|delete)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*(fn\s*\(|function\s*\()/m', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $method = strtoupper($match[1]);
                $path = $match[2];
                $issues[] = "config/routes.php: closure on {$method} {$path}. "
                    . "Fix: php fw make:controller then use [Controller::class, 'method'] syntax";
            }
        }

        return $issues;
    }

    /**
     * @return Generator<string>
     */
    private function phpFiles(string $directory): Generator
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
