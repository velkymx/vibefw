<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;
use Fw\Queue\Queue;
use RuntimeException;
use Throwable;

/**
 * Process queue jobs.
 */
final class QueueWorkCommand extends Command
{
    protected string $name = 'queue:work';

    protected string $description = 'Start processing jobs on the queue';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function configure(): void
    {
        $this->addOption('queue', 'The queue to process', 'default', 'q');
        $this->addOption('sleep', 'Seconds to sleep when no jobs available', 3, 's');
        $this->addOption('tries', 'Number of times to attempt a job', 3, 't');
        $this->addOption('once', 'Only process the next job on the queue', false);
        $this->addOption('stop-when-empty', 'Stop when the queue is empty', false);
    }

    public function handle(): int
    {
        $basePath = $this->app->getBasePath();
        $queueName = $this->option('queue') ?? 'default';
        $sleep = (int) ($this->option('sleep') ?? 3);
        $maxTries = (int) ($this->option('tries') ?? 3);
        $once = $this->hasOption('once');
        $stopWhenEmpty = $this->hasOption('stop-when-empty');

        // Load queue configuration
        $configPath = $basePath . '/config/queue.php';
        if (!file_exists($configPath)) {
            $this->error('Queue configuration not found.');
            return 1;
        }

        $config = require $configPath;

        $this->info("Processing queue: $queueName");
        $this->comment("Press Ctrl+C to stop");
        $this->newLine();

        // Set up signal handling for graceful shutdown
        $shouldStop = false;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () use (&$shouldStop): void {
                $shouldStop = true;
            });
            pcntl_signal(SIGINT, function () use (&$shouldStop): void {
                $shouldStop = true;
            });
        }

        $processed = 0;
        $failed = 0;

        while (!$shouldStop) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            try {
                $job = Queue::pop($queueName);

                if ($job === null) {
                    if ($stopWhenEmpty || $once) {
                        break;
                    }

                    sleep($sleep);
                    continue;
                }

                $this->processJob($job, $maxTries, $processed, $failed);

                if ($once) {
                    break;
                }
            } catch (Throwable $e) {
                $this->error("Worker error: " . $e->getMessage());
                sleep($sleep);
            }
        }

        $this->newLine();
        $this->info("Worker stopped. Processed: $processed, Failed: $failed");

        return 0;
    }

    private function processJob(array $job, int $maxTries, int &$processed, int &$failed): void
    {
        $handler = $job['handler'] ?? 'Unknown';
        $attempts = $job['attempts'] ?? 1;

        $this->line("Processing: $handler (attempt $attempts)");

        try {
            // Execute the job
            $handlerClass = $job['handler'];
            $payload = $job['payload'] ?? [];

            if (!class_exists($handlerClass)) {
                throw new RuntimeException("Job handler not found: $handlerClass");
            }

            $instance = new $handlerClass();
            $instance->handle($payload);

            $processed++;
            $this->success("Completed: $handler");
        } catch (Throwable $e) {
            $failed++;
            $this->error("Failed: $handler - " . $e->getMessage());

            // Re-queue for retry if under max attempts
            if ($attempts < $maxTries) {
                // Job will be retried by the queue driver
            }
        }
    }
}
