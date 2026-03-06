<?php

declare(strict_types=1);

namespace Fw\Core;

use Fw\Log\Logger;

/**
 * Centralized error and exception handling.
 *
 * Handles routing errors (404, 405) and uncaught exceptions,
 * providing appropriate responses based on debug mode.
 */
final class ErrorHandler
{
    public function __construct(
        private Response $response,
        private Logger $log,
        private Config $config,
    ) {}

    /**
     * Create a response for routing errors.
     */
    public function createRoutingResponse(RouteNotFound|MethodNotAllowed $error, Request $request): Response
    {
        $this->log->warning('Route error: {message}', [
            'message' => $error->getMessage(),
            'method' => $error->method,
            'uri' => $error->uri,
        ]);

        $response = new Response();

        if ($error instanceof MethodNotAllowed) {
            return $response
                ->setStatus(405)
                ->header('Allow', implode(', ', $error->allowedMethods))
                ->setBody('405 Method Not Allowed');
        }

        return $response->setStatus(404)->setBody('404 Not Found');
    }

    /**
     * Create a response for uncaught exceptions.
     */
    public function createExceptionResponse(\Throwable $e, Request $request): Response
    {
        $debug = $this->config->get('app.debug', false);

        // Log the exception
        $this->log->error('Uncaught exception: {message}', [
            'message' => $e->getMessage(),
            'exception' => $e,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'uri' => $request->uri,
            'method' => $request->method,
        ]);

        $response = new Response();
        $response->setStatus(500);

        if ($debug) {
            return $response
                ->contentType('text/html')
                ->setBody($this->renderDebugErrorHtml($e));
        }

        return $response->setBody('500 Internal Server Error');
    }

    /**
     * Render detailed error information for debug mode.
     */
    private function renderDebugErrorHtml(\Throwable $e): string
    {
        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
        $isProduction = in_array($env, ['production', 'prod', 'live'], true);
        $securityWarning = '';

        if ($isProduction) {
            $securityWarning = '
        <div class="security-warning">
            <strong>SECURITY WARNING:</strong> Debug mode is enabled in a production environment!
            This exposes sensitive information to attackers. Set <code>app.debug = false</code> immediately.
        </div>';
        }

        return sprintf(
            '<!DOCTYPE html>
<html>
<head>
    <title>Error: %s</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 40px; background: #f5f5f5; }
        .error-container { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); padding: 30px; max-width: 1200px; }
        h1 { color: #e53935; margin-top: 0; }
        .message { font-size: 18px; color: #333; margin-bottom: 20px; }
        .location { background: #fafafa; padding: 15px; border-radius: 4px; font-family: monospace; margin-bottom: 20px; }
        .trace { background: #263238; color: #aed581; padding: 20px; border-radius: 4px; overflow-x: auto; font-family: monospace; font-size: 13px; line-height: 1.6; }
        .trace-line { margin: 2px 0; }
        .trace-file { color: #80cbc4; }
        .trace-line-num { color: #ffcc80; }
        .security-warning { background: #ffebee; border: 2px solid #e53935; color: #b71c1c; padding: 15px; border-radius: 4px; margin-bottom: 20px; font-weight: 500; }
        .security-warning code { background: #ffcdd2; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <div class="error-container">%s
        <h1>%s</h1>
        <div class="message">%s</div>
        <div class="location">
            <strong>File:</strong> %s<br>
            <strong>Line:</strong> %d
        </div>
        <div class="trace">%s</div>
    </div>
</body>
</html>',
            htmlspecialchars(get_class($e), ENT_QUOTES, 'UTF-8'),
            $securityWarning,
            htmlspecialchars(get_class($e), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8'),
            $e->getLine(),
            $this->formatTrace($e)
        );
    }

    /**
     * Format the stack trace for display.
     */
    private function formatTrace(\Throwable $e): string
    {
        $lines = [];
        foreach ($e->getTrace() as $i => $frame) {
            $file = $frame['file'] ?? '[internal]';
            $line = $frame['line'] ?? 0;
            $function = $frame['function'] ?? '';
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $call = $class ? "{$class}{$type}{$function}()" : "{$function}()";

            $lines[] = sprintf(
                '<div class="trace-line">#%d <span class="trace-file">%s</span>:<span class="trace-line-num">%d</span> %s</div>',
                $i,
                htmlspecialchars($file, ENT_QUOTES, 'UTF-8'),
                $line,
                htmlspecialchars($call, ENT_QUOTES, 'UTF-8')
            );
        }
        return implode("\n", $lines);
    }

    /**
     * @deprecated Use createRoutingResponse
     */
    public function handleRoutingError(RouteNotFound|MethodNotAllowed $error): void
    {
        // This is now handled by HttpKernel returning the response
    }

    /**
     * @deprecated Use createExceptionResponse
     */
    public function handleException(\Throwable $e, ?Request $request = null): void
    {
        // This is now handled by HttpKernel returning the response
    }

    public function notFound(string $message = '404 Not Found'): Response
    {
        return (new Response())->setStatus(404)->setBody($message);
    }

    public function forbidden(string $message = '403 Forbidden'): Response
    {
        return (new Response())->setStatus(403)->setBody($message);
    }

    public function badRequest(string $message = '400 Bad Request'): Response
    {
        return (new Response())->setStatus(400)->setBody($message);
    }

    public function serverError(string $message = '500 Internal Server Error'): Response
    {
        return (new Response())->setStatus(500)->setBody($message);
    }
}
