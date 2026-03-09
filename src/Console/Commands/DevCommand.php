<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Command;

/**
 * Command to start the VibeFW development ecosystem.
 *
 * Runs FrankenPHP and Vite concurrently.
 */
final class DevCommand extends Command
{
    protected string $name = 'dev';

    protected string $description = 'Start backend and frontend development servers concurrently';

    public function handle(): int
    {
        $this->newLine();
        $this->info('🛰️ Starting VibeFW Development Ecosystem...');

        $backendPort = 8000;
        $frontendDir = BASE_PATH . '/frontend';

        // 1. Check if frontend exists
        $hasFrontend = is_dir($frontendDir);

        if ($hasFrontend) {
            // Check for node_modules
            if (!is_dir($frontendDir . '/node_modules')) {
                $this->warning('Frontend dependencies not found.');
                if ($this->confirm('Would you like to run "npm install" now?', true)) {
                    $this->info('📦 Installing dependencies... this may take a minute.');
                    passthru('cd ' . escapeshellarg($frontendDir) . ' && npm install');
                } else {
                    $this->error('Vite cannot start without dependencies. Starting backend only.');
                    $hasFrontend = false;
                }
            }
        }

        if (!$hasFrontend) {
            $this->warning('Starting backend only.');
            return $this->startBackend($backendPort);
        }

        // Check if backend port is already in use
        if ($this->isPortInUse($backendPort)) {
            $this->error("Error: Port $backendPort is already in use.");
            $this->line("Please stop the existing process or use a different port.");
            return 1;
        }

        // 2. Concurrent execution
        $this->info("Backend:  http://localhost:$backendPort");
        $this->info("Frontend: http://localhost:5173");
        $this->newLine();

        $commands = [
            'php fw serve --port=' . $backendPort,
            'cd ' . escapeshellarg($frontendDir) . ' && npm run dev',
        ];

        // Simple parallel execution using pcntl_fork if available, or just outputting instructions
        if (function_exists('pcntl_fork')) {
            $this->runParallel($commands);
        } else {
            $this->error('Parallel execution (pcntl) not available.');
            $this->line('Please run these in separate terminals:');
            foreach ($commands as $cmd) {
                $this->line("  <comment>$cmd</comment>");
            }
        }

        return 0;
    }

    private function startBackend(int $port): int
    {
        passthru("php fw serve --port=$port");
        return 0;
    }

    private function isPortInUse(int $port): bool
    {
        $connection = @fsockopen('localhost', $port);
        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }
        return false;
    }

    private function runParallel(array $commands): void
    {
        foreach ($commands as $index => $cmd) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->error('Could not fork process.');
                return;
            }
            if ($pid) {
                // Parent process - continue to next command
                continue;
            }
            // Child process - execute command
            passthru($cmd);
            exit;

        }

        // Parent waits for children
        while (pcntl_waitpid(0, $status) !== -1);
    }
}
