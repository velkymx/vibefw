<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use FilesystemIterator;
use Fw\Console\Application;
use Fw\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Clear application cache.
 */
final class CacheClearCommand extends Command
{
    protected string $name = 'cache:clear';

    protected string $description = 'Clear all application caches';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addOption('views', 'Only clear view cache', false);
        $this->addOption('config', 'Only clear config cache', false);
        $this->addOption('routes', 'Only clear route cache', false);
    }

    public function handle(): int
    {
        $basePath = $this->app->getBasePath();
        $cacheDir = $basePath . '/storage/cache';

        $this->info('Clearing caches...');
        $this->newLine();

        $onlyViews = $this->hasOption('views');
        $onlyConfig = $this->hasOption('config');
        $onlyRoutes = $this->hasOption('routes');
        $clearAll = ! $onlyViews && ! $onlyConfig && ! $onlyRoutes;

        $cleared = 0;

        // View cache
        if ($clearAll || $onlyViews) {
            $viewCache = $cacheDir . '/views';
            $cleared += $this->clearDirectory($viewCache, 'View cache');
        }

        // Config cache
        if ($clearAll || $onlyConfig) {
            $configCache = $cacheDir . '/config.php';
            if (file_exists($configCache)) {
                unlink($configCache);
                $this->success('Config cache cleared');
                $cleared++;
            }
        }

        // Route cache
        if ($clearAll || $onlyRoutes) {
            $routeCache = $cacheDir . '/routes.php';
            if (file_exists($routeCache)) {
                unlink($routeCache);
                $this->success('Route cache cleared');
                $cleared++;
            }
        }

        // General cache files
        if ($clearAll) {
            $cleared += $this->clearDirectory($cacheDir, 'Application cache', ['views', '.gitkeep']);
        }

        $this->newLine();
        if ($cleared > 0) {
            $this->info('Cache cleared successfully.');
        } else {
            $this->comment('No cache files to clear.');
        }

        return 0;
    }

    /**
     * Clear a cache directory.
     *
     * @param array<string> $exclude Files/directories to exclude
     */
    private function clearDirectory(string $dir, string $name, array $exclude = []): int
    {
        if (! is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $basename = $file->getBasename();

            // Skip excluded
            if (in_array($basename, $exclude, true)) {
                continue;
            }

            // Skip if parent is excluded
            $relativePath = str_replace($dir . '/', '', $file->getPathname());
            $skip = false;
            foreach ($exclude as $ex) {
                if (str_starts_with($relativePath, $ex . '/')) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
                $count++;
            }
        }

        if ($count > 0) {
            $this->success("$name: $count file(s) cleared");
        }

        return $count;
    }
}
