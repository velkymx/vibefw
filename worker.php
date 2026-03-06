#!/usr/bin/env php
<?php

declare(strict_types=1);

define('BASE_PATH', __DIR__);

require BASE_PATH . '/vendor/autoload.php';

use Fw\Core\Application;
use Fw\Queue\Queue;
use Fw\Queue\Worker;

// Parse command line arguments
$options = getopt('q:s:j:t:m:h', ['queue:', 'sleep:', 'max-jobs:', 'max-time:', 'memory:', 'help', 'once']);

if (isset($options['h']) || isset($options['help'])) {
    echo <<<HELP
    Queue Worker

    Usage: php worker.php [options]

    Options:
      -q, --queue <name>      Queue to process (default: default)
      -s, --sleep <seconds>   Seconds to sleep when no jobs (default: 3)
      -j, --max-jobs <count>  Stop after processing N jobs (default: unlimited)
      -t, --max-time <secs>   Stop after N seconds (default: unlimited)
      -m, --memory <MB>       Stop if memory exceeds N MB (default: 128)
          --once              Process a single job and exit
      -h, --help              Show this help message

    HELP;
    exit(0);
}

$queueName = $options['q'] ?? $options['queue'] ?? 'default';
$sleep = (int) ($options['s'] ?? $options['sleep'] ?? 3);
$maxJobs = (int) ($options['j'] ?? $options['max-jobs'] ?? 0);
$maxTime = (int) ($options['t'] ?? $options['max-time'] ?? 0);
$memory = (int) ($options['m'] ?? $options['memory'] ?? 128);
$once = isset($options['once']);

// 1. Initialize Application
$app = Application::getInstance();
$container = $app->getContainer();

// 2. Resolve Worker from container (it will auto-resolve its Queue dependency)
$worker = $container->get(Worker::class);

// 3. Configure worker
$worker->sleep($sleep)
    ->maxJobs($maxJobs)
    ->maxTime($maxTime)
    ->memory($memory);

// 4. Run
if ($once) {
    $processed = $worker->workOnce($queueName);
    exit($processed ? 0 : 1);
} else {
    $worker->work($queueName);
}
