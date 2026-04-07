<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use FilesystemIterator;
use Fw\Console\Application;
use Fw\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Auto-correct common v3 convention violations.
 */
final class FixCommand extends Command
{
    protected string $name = 'fix';

    protected string $description = 'Auto-correct common convention violations ($guarded→$fillable, strict_types, etc.)';

    private int $fixCount = 0;

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addOption('dry-run', 'Show what would be fixed without changing files', false);
    }

    public function handle(): int
    {
        $basePath = $this->app->getBasePath();
        $dryRun = $this->option('dry-run') === true;

        $this->info($dryRun ? 'Scanning for fixable issues (dry run)...' : 'Fixing convention violations...');
        $this->newLine();

        $this->fixStrictTypes($basePath, $dryRun);
        $this->fixGuardedToFillable($basePath, $dryRun);

        $this->newLine();
        $this->line(str_repeat('=', 50));

        if ($this->fixCount === 0) {
            $this->success('No issues to fix. Code is clean!');
            return 0;
        }

        if ($dryRun) {
            $this->info("{$this->fixCount} issue(s) can be auto-fixed. Run without --dry-run to apply.");
        } else {
            $this->success("{$this->fixCount} issue(s) fixed.");
            $this->line('Run php fw check to verify.');
        }

        return 0;
    }

    private function fixStrictTypes(string $basePath, bool $dryRun): void
    {
        $dirs = ['app/Controllers', 'app/Models', 'app/Requests'];

        foreach ($dirs as $dir) {
            $path = $basePath . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }

            foreach ($this->phpFiles($path) as $file) {
                $content = file_get_contents($file);
                if ($content === false) {
                    continue;
                }

                if (str_contains($content, 'declare(strict_types=1)')) {
                    continue;
                }

                $relative = str_replace($basePath . '/', '', $file);

                if ($dryRun) {
                    $this->line("  Would add strict_types to {$relative}");
                    $this->fixCount++;
                    continue;
                }

                // Insert declare(strict_types=1) after <?php
                $fixed = preg_replace(
                    '/^<\?php\s*/s',
                    "<?php\n\ndeclare(strict_types=1);\n\n",
                    $content,
                    1
                );

                if ($fixed !== null && $fixed !== $content) {
                    file_put_contents($file, $fixed);
                    $this->line('  ' . $this->output->color('✓', 'green') . " Added strict_types: {$relative}");
                    $this->fixCount++;
                }
            }
        }
    }

    private function fixGuardedToFillable(string $basePath, bool $dryRun): void
    {
        $path = $basePath . '/app/Models';
        if (!is_dir($path)) {
            return;
        }

        foreach ($this->phpFiles($path) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            if (!preg_match('/\$guarded\s*=/', $content)) {
                continue;
            }

            $relative = str_replace($basePath . '/', '', $file);

            if ($dryRun) {
                $this->line("  Would convert \$guarded → \$fillable in {$relative}");
                $this->fixCount++;
                continue;
            }

            // Replace $guarded with $fillable = [] (user must populate)
            $fixed = preg_replace(
                '/protected\s+static\s+array\s+\$guarded\s*=\s*\[[^\]]*\]\s*;/',
                'protected static array $fillable = [];',
                $content
            );

            if ($fixed !== null && $fixed !== $content) {
                file_put_contents($file, $fixed);
                $this->line('  ' . $this->output->color('✓', 'green') . " Converted \$guarded → \$fillable: {$relative}");
                $this->line('    ' . $this->output->color('→', 'yellow') . ' Add your fillable fields to $fillable array');
                $this->fixCount++;
            }
        }
    }

    /**
     * @return \Generator<string>
     */
    private function phpFiles(string $directory): \Generator
    {
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
