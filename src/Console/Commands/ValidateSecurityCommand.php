<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use FilesystemIterator;
use Fw\Console\Application;
use Fw\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Security scanner for detecting common vulnerabilities.
 */
final class ValidateSecurityCommand extends Command
{
    private const SEVERITY_CRITICAL = 'CRITICAL';
    private const SEVERITY_HIGH = 'HIGH';
    private const SEVERITY_MEDIUM = 'MEDIUM';
    private const SEVERITY_LOW = 'LOW';

    protected string $name = 'validate:security';

    protected string $description = 'Scan code for security vulnerabilities';

    /** @var array<array{file: string, line: int, severity: string, message: string, code: string}> */
    private array $issues = [];

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addOption('path', 'Specific path to scan', null);
    }

    public function handle(): int
    {
        $this->issues = [];
        $basePath = $this->app->getBasePath();

        $this->info('Running security scan...');
        $this->newLine();

        $specificPath = $this->option('path');
        if ($specificPath !== null) {
            $directories = [$basePath . '/' . ltrim((string) $specificPath, '/')];
        } else {
            $directories = [
                $basePath . '/src',
                $basePath . '/app',
            ];
        }

        foreach ($directories as $dir) {
            if (is_dir($dir)) {
                $this->scanDirectory($dir, $basePath);
            }
        }

        return $this->outputResults();
    }

    private function scanDirectory(string $directory, string $basePath): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->scanFile($file->getPathname(), $basePath);
            }
        }
    }

    private function scanFile(string $filePath, string $basePath): void
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return;
        }

        $lines = explode("\n", $content);
        $relativePath = str_replace($basePath . '/', '', $filePath);

        foreach ($lines as $lineNum => $line) {
            $lineNumber = $lineNum + 1;

            // Skip comments and lines marked with suppression
            $trimmedLine = trim($line);
            if (str_contains($line, '@nosecurity') || str_contains($line, '@ignore-security')) {
                continue;
            }

            if (str_starts_with($trimmedLine, '//') || str_starts_with($trimmedLine, '*') || str_starts_with($trimmedLine, '/*')) {
                continue;
            }

            $this->checkUnserialize($relativePath, $lineNumber, $line);
            $this->checkEval($relativePath, $lineNumber, $line);
            $this->checkShellExecution($relativePath, $lineNumber, $line);
            $this->checkSqlInjection($relativePath, $lineNumber, $line);
            $this->checkXss($relativePath, $lineNumber, $line);
            $this->checkFileInclusion($relativePath, $lineNumber, $line);
            $this->checkDebugCode($relativePath, $lineNumber, $line);
            $this->checkHardcodedCredentials($relativePath, $lineNumber, $line);
            $this->checkWeakCrypto($relativePath, $lineNumber, $line);
            $this->checkHeaderInjection($relativePath, $lineNumber, $line);
            $this->checkOpenRedirect($relativePath, $lineNumber, $line);
        }
    }

    private function checkUnserialize(string $file, int $line, string $content): void
    {
        if (preg_match('/\bunserialize\s*\(/', $content)) {
            if (! str_contains($content, 'allowed_classes')) {
                $this->addIssue(
                    $file,
                    $line,
                    self::SEVERITY_CRITICAL,
                    'unserialize() without allowed_classes restriction - RCE vulnerability',
                    'UNSERIALIZE_RCE'
                );
            }
        }
    }

    private function checkEval(string $file, int $line, string $content): void
    {
        // Skip security scanner files (they contain patterns we're looking for)
        if (str_contains($file, 'SecurityScanCommand') || str_contains($file, 'ValidateSecurityCommand')) {
            return;
        }

        // Skip if this is inside a regex pattern
        if (str_contains($content, "preg_match(")) {
            return;
        }

        if (preg_match('/\beval\s*\(/', $content)) {
            $this->addIssue(
                $file,
                $line,
                self::SEVERITY_CRITICAL,
                'eval() found - arbitrary code execution vulnerability',
                'EVAL_INJECTION'
            );
        }

        if (preg_match('/\bcreate_function\s*\(/', $content)) {
            $this->addIssue(
                $file,
                $line,
                self::SEVERITY_CRITICAL,
                'create_function() found - deprecated and dangerous',
                'CREATE_FUNCTION'
            );
        }

        // Match preg_replace with /e modifier: preg_replace('/pattern/e', ...)
        // Must have /e as the modifier right after the closing delimiter
        if (preg_match('/\bpreg_replace\s*\(\s*[\'"][^\'"]*\/[imsxADSUXJu]*e[imsxADSUXJu]*[\'"]/', $content)) {
            $this->addIssue(
                $file,
                $line,
                self::SEVERITY_CRITICAL,
                'preg_replace() with /e modifier - code execution vulnerability',
                'PREG_REPLACE_EVAL'
            );
        }
    }

    private function checkShellExecution(string $file, int $line, string $content): void
    {
        // Skip PDO exec method - it's not shell execution
        if (str_contains($content, '->exec(') || str_contains($content, 'getPdo()->exec')) {
            return;
        }

        $dangerousFunctions = ['shell_exec', 'system', 'passthru', 'popen', 'proc_open'];

        foreach ($dangerousFunctions as $func) {
            if (preg_match('/\b' . $func . '\s*\(/', $content)) {
                if (! str_contains($content, 'escapeshellarg') && ! str_contains($content, 'escapeshellcmd')) {
                    $this->addIssue(
                        $file,
                        $line,
                        self::SEVERITY_HIGH,
                        "$func() without proper escaping - command injection risk",
                        'COMMAND_INJECTION'
                    );
                }
            }
        }

        // Check for standalone exec() function (not method call)
        if (preg_match('/\bexec\s*\(/', $content) && ! str_contains($content, '->exec(')) {
            if (! str_contains($content, 'escapeshellarg') && ! str_contains($content, 'escapeshellcmd')) {
                $this->addIssue(
                    $file,
                    $line,
                    self::SEVERITY_HIGH,
                    'exec() without proper escaping - command injection risk',
                    'COMMAND_INJECTION'
                );
            }
        }

        // Backtick execution - skip if it looks like MySQL identifier quoting in strings
        // MySQL uses `identifier` but that's not shell execution
        if (preg_match('/`[^`]*\$/', $content)) {
            // Skip if inside quotes (MySQL identifier pattern)
            if (! preg_match('/[\'"][^"\']*`[^`]*\$[^`]*`[^"\']*[\'"]/', $content)) {
                $this->addIssue(
                    $file,
                    $line,
                    self::SEVERITY_HIGH,
                    'Backtick execution with variable - command injection risk',
                    'BACKTICK_INJECTION'
                );
            }
        }
    }

    private function checkSqlInjection(string $file, int $line, string $content): void
    {
        // Skip known safe patterns in the framework
        if (str_contains($content, '$this->table') ||
            str_contains($content, '$this->quoteIdentifier') ||
            str_contains($content, '$this->quoteTable()') ||
            str_contains($content, '$this->compileWheres()')) {
            return;
        }

        if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|WHERE|FROM)\b[^;]*\.\s*\$/', $content)) {
            $this->addIssue(
                $file,
                $line,
                self::SEVERITY_HIGH,
                'Possible SQL injection - string concatenation with variable',
                'SQL_INJECTION'
            );
        }

        if (preg_match('/"[^"]*\b(SELECT|INSERT|UPDATE|DELETE)\b[^"]*\$[a-zA-Z_]/', $content)) {
            // Skip safe interpolation
            if (str_contains($content, '$this->table') || str_contains($content, '$table')) {
                return;
            }

            $this->addIssue(
                $file,
                $line,
                self::SEVERITY_HIGH,
                'Possible SQL injection - variable in double-quoted SQL',
                'SQL_INJECTION_INTERPOLATION'
            );
        }
    }

    private function checkXss(string $file, int $line, string $content): void
    {
        if (preg_match('/echo\s+\$[a-zA-Z_][a-zA-Z0-9_]*\s*;/', $content)) {
            if (! str_contains($content, 'htmlspecialchars') && ! str_contains($content, 'htmlentities')) {
                $this->addIssue(
                    $file,
                    $line,
                    self::SEVERITY_MEDIUM,
                    'echo without HTML escaping - potential XSS',
                    'XSS_ECHO'
                );
            }
        }

        if (preg_match('/<\?=\s*\$[a-zA-Z_]/', $content)) {
            if (! str_contains($content, 'htmlspecialchars')) {
                $this->addIssue(
                    $file,
                    $line,
                    self::SEVERITY_MEDIUM,
                    'Short echo tag without escaping - potential XSS',
                    'XSS_SHORT_TAG'
                );
            }
        }
    }

    private function checkFileInclusion(string $file, int $line, string $content): void
    {
        $includeFunctions = ['include', 'include_once', 'require', 'require_once'];

        foreach ($includeFunctions as $func) {
            if (preg_match('/\b' . $func . '\s*\(?\s*\$/', $content)) {
                $this->addIssue(
                    $file,
                    $line,
                    self::SEVERITY_HIGH,
                    "$func with variable - local/remote file inclusion risk",
                    'FILE_INCLUSION'
                );
            }
        }
    }

    private function checkDebugCode(string $file, int $line, string $content): void
    {
        $debugFunctions = ['var_dump', 'print_r', 'debug_print_backtrace', 'dd', 'dump'];

        foreach ($debugFunctions as $func) {
            if (preg_match('/\b' . $func . '\s*\(/', $content)) {
                $this->addIssue(
                    $file,
                    $line,
                    self::SEVERITY_LOW,
                    "Debug function $func() found - remove before production",
                    'DEBUG_CODE'
                );
            }
        }

        if (preg_match('/error_reporting\s*\(\s*E_ALL\s*\)/', $content)) {
            $this->addIssue(
                $file,
                $line,
                self::SEVERITY_LOW,
                'error_reporting(E_ALL) found - disable in production',
                'ERROR_REPORTING'
            );
        }
    }

    private function checkHardcodedCredentials(string $file, int $line, string $content): void
    {
        if (preg_match('/\$(password|passwd|pwd|secret|api_?key)\s*=\s*[\'"][^\'"]+[\'"]/i', $content)) {
            $this->addIssue(
                $file,
                $line,
                self::SEVERITY_HIGH,
                'Possible hardcoded credential - use environment variables',
                'HARDCODED_CREDENTIAL'
            );
        }

        if (preg_match('/mysql:.*password=/i', $content)) {
            $this->addIssue(
                $file,
                $line,
                self::SEVERITY_HIGH,
                'Hardcoded database password in connection string',
                'HARDCODED_DB_PASSWORD'
            );
        }
    }

    private function checkWeakCrypto(string $file, int $line, string $content): void
    {
        if (preg_match('/md5\s*\(\s*\$.*password/i', $content)) {
            $this->addIssue(
                $file,
                $line,
                self::SEVERITY_HIGH,
                'MD5 used for password hashing - use password_hash()',
                'WEAK_PASSWORD_HASH'
            );
        }

        if (preg_match('/sha1\s*\(\s*\$.*password/i', $content)) {
            $this->addIssue(
                $file,
                $line,
                self::SEVERITY_HIGH,
                'SHA1 used for password hashing - use password_hash()',
                'WEAK_PASSWORD_HASH'
            );
        }

        if (preg_match('/\brand\s*\(|\bmt_rand\s*\(/', $content)) {
            if (str_contains($content, 'token') || str_contains($content, 'password') || str_contains($content, 'secret')) {
                $this->addIssue(
                    $file,
                    $line,
                    self::SEVERITY_MEDIUM,
                    'Weak random function for security token - use random_bytes()',
                    'WEAK_RANDOM'
                );
            }
        }
    }

    private function checkHeaderInjection(string $file, int $line, string $content): void
    {
        if (preg_match('/\bheader\s*\([^)]*\$/', $content)) {
            if (! str_contains($content, 'preg_match') && ! str_contains($content, 'filter_var')) {
                $this->addIssue(
                    $file,
                    $line,
                    self::SEVERITY_MEDIUM,
                    'header() with variable - possible header injection',
                    'HEADER_INJECTION'
                );
            }
        }
    }

    private function checkOpenRedirect(string $file, int $line, string $content): void
    {
        if (preg_match('/header\s*\(\s*[\'"]Location:\s*[\'"]?\s*\.\s*\$/', $content)) {
            $this->addIssue(
                $file,
                $line,
                self::SEVERITY_MEDIUM,
                'Redirect with user input - possible open redirect',
                'OPEN_REDIRECT'
            );
        }

        if (preg_match('/->redirect\s*\(\s*\$/', $content)) {
            $this->addIssue(
                $file,
                $line,
                self::SEVERITY_MEDIUM,
                'Redirect method with variable - validate URL is same-origin',
                'OPEN_REDIRECT'
            );
        }
    }

    private function addIssue(string $file, int $line, string $severity, string $message, string $code): void
    {
        $this->issues[] = [
            'file' => $file,
            'line' => $line,
            'severity' => $severity,
            'message' => $message,
            'code' => $code,
        ];
    }

    private function outputResults(): int
    {
        if (empty($this->issues)) {
            $this->success('No security issues found');
            return 0;
        }

        // Group by severity
        $bySeverity = [];
        foreach ($this->issues as $issue) {
            $bySeverity[$issue['severity']][] = $issue;
        }

        $severityColors = [
            self::SEVERITY_CRITICAL => 'red',
            self::SEVERITY_HIGH => 'red',
            self::SEVERITY_MEDIUM => 'yellow',
            self::SEVERITY_LOW => 'cyan',
        ];

        foreach ([self::SEVERITY_CRITICAL, self::SEVERITY_HIGH, self::SEVERITY_MEDIUM, self::SEVERITY_LOW] as $severity) {
            if (! isset($bySeverity[$severity])) {
                continue;
            }

            $color = $severityColors[$severity];
            $this->line($this->output->color("[$severity]", $color));
            $this->newLine();

            foreach ($bySeverity[$severity] as $issue) {
                $this->line("  {$issue['file']}:{$issue['line']}");
                $this->line("    {$issue['message']}");
                $this->comment("    Code: {$issue['code']}");
                $this->newLine();
            }
        }

        // Summary
        $summary = [
            'total' => count($this->issues),
            'critical' => count($bySeverity[self::SEVERITY_CRITICAL] ?? []),
            'high' => count($bySeverity[self::SEVERITY_HIGH] ?? []),
            'medium' => count($bySeverity[self::SEVERITY_MEDIUM] ?? []),
            'low' => count($bySeverity[self::SEVERITY_LOW] ?? []),
        ];

        $this->line("Summary: {$summary['total']} issues " .
            "(Critical: {$summary['critical']}, High: {$summary['high']}, " .
            "Medium: {$summary['medium']}, Low: {$summary['low']})");

        // Return non-zero only if critical issues found
        if ($summary['critical'] > 0) {
            return 1;
        }

        return 0;
    }
}
