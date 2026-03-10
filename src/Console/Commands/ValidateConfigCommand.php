<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Config\ConfigValidator;
use Fw\Console\Application;
use Fw\Console\Command;
use Throwable;

/**
 * Validate configuration files against schemas.
 */
final class ValidateConfigCommand extends Command
{
    protected string $name = 'validate:config';

    protected string $description = 'Validate configuration files';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function handle(): int
    {
        $basePath = $this->app->getBasePath();

        $this->info('Validating configuration files...');
        $this->newLine();

        // Register default schemas
        ConfigValidator::registerDefaultSchemas();

        // Load and validate each config file
        $configFiles = [
            'app' => $basePath . '/config/app.php',
            'database' => $basePath . '/config/database.php',
            'cache' => $basePath . '/config/cache.php',
            'queue' => $basePath . '/config/queue.php',
        ];

        $configs = [];
        $loadErrors = [];

        foreach ($configFiles as $name => $path) {
            if (!file_exists($path)) {
                $this->comment("Skipping $name (file not found)");
                continue;
            }

            try {
                $config = require $path;
                if (!is_array($config)) {
                    $loadErrors[] = "$name: Config must return an array";
                    continue;
                }
                $configs[$name] = $config;
                $this->line("Loaded: config/$name.php");
            } catch (Throwable $e) {
                $loadErrors[] = "$name: " . $e->getMessage();
            }
        }

        if (!empty($loadErrors)) {
            $this->newLine();
            $this->error('Config load errors:');
            foreach ($loadErrors as $error) {
                $this->line("  - $error");
            }
            return 1;
        }

        // Validate all configs
        $this->newLine();
        $this->info('Running validation...');
        $this->newLine();

        $result = ConfigValidator::validateAll($configs);

        if ($result->isErr()) {
            $errors = $result->getError();
            $this->error('Validation errors found:');
            $this->newLine();
            foreach ($errors as $error) {
                $this->line("  - $error");
            }
            $this->newLine();
            $this->line('Total: ' . count($errors) . ' error(s)');
            return 1;
        }

        $this->success('All configuration files are valid');
        return 0;
    }
}
