<?php

declare(strict_types=1);

namespace Fw\Middleware;

use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Core\Response;

final class LogMiddleware implements MiddlewareInterface
{
    private Application $app;

    private ?string $logFile;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->logFile = $app->config('app.log_file', BASE_PATH . '/storage/logs/access.log');
    }

    public function handle(Request $request, callable $next): Response|string|array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $response = $next($request);

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $memoryUsed = round((memory_get_usage() - $startMemory) / 1024, 2);

        $this->log($request, $response, $duration, $memoryUsed);

        return $response;
    }

    private function log(Request $request, Response|string|array $response, float $duration, float $memoryKb): void
    {
        $statusCode = $response instanceof Response
            ? $response->getStatusCode()
            : 200;

        $entry = sprintf(
            "[%s] %s %s %s %d %sms %sKB %s\n",
            date('Y-m-d H:i:s'),
            $request->ip(),
            $request->method,
            $request->uri,
            $statusCode,
            $duration,
            $memoryKb,
            $request->userAgent()
        );

        if ($this->logFile !== null) {
            $dir = dirname($this->logFile);

            if (!is_dir($dir)) {
                mkdir($dir, 0o750, true);
            }

            file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);
        }
    }
}
