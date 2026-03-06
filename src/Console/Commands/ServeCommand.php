<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;

/**
 * Start the built-in PHP development server.
 */
final class ServeCommand extends Command
{
    protected string $name = 'serve';

    protected string $description = 'Start the development server';

    public function __construct(
        private Application $app,
    ) {}

    public function configure(): void
    {
        $this->addOption('host', 'The host address', 'localhost');
        $this->addOption('port', 'The port to serve on', '8000', 'p');
    }

    public function handle(): int
    {
        $host = $this->option('host') ?: 'localhost';
        $port = $this->option('port') ?: '8000';
        $basePath = $this->app->getBasePath();

        // Validate port
        $port = (int) $port;
        if ($port < 1 || $port > 65535) {
            $this->error('Invalid port number. Must be between 1 and 65535.');
            return 1;
        }

        // Check if public directory exists
        $docRoot = $basePath . '/public';
        if (! is_dir($docRoot)) {
            $this->error('Public directory not found: public/');
            return 1;
        }

        // Check if router file exists
        $router = $basePath . '/public/index.php';
        if (! file_exists($router)) {
            $this->error('Entry point not found: public/index.php');
            return 1;
        }

        $this->newLine();
        $this->info('VibeFW Development Server');
        $this->newLine();
        $this->line("Server running at: " . $this->output->color("http://$host:$port", 'cyan'));
        $this->line("Document root: " . $this->output->color($docRoot, 'gray'));
        $this->newLine();
        $this->comment('Press Ctrl+C to stop the server');
        $this->newLine();

        // Start the server
        $command = sprintf(
            'php -S %s:%d -t %s %s',
            escapeshellarg($host),
            $port,
            escapeshellarg($docRoot),
            escapeshellarg($router)
        );

        passthru($command, $exitCode);

        return $exitCode;
    }
}
