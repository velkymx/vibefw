<?php

declare(strict_types=1);

namespace Fw\Middleware;

use Fw\Auth\Auth;
use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Http\ApiResponse;

/**
 * SPA cookie-based authentication middleware.
 *
 * Uses existing session authentication with CSRF protection.
 * Validates CSRF token from X-XSRF-TOKEN header.
 * Enforces same-origin via Referer/Origin header check.
 */
final class SpaAuthMiddleware implements MiddlewareInterface
{
    private Application $app;

    private array $config;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->loadConfig();
    }

    public function handle(Request $request, callable $next): Response|string|array
    {
        // Validate origin/referer for same-origin enforcement
        if (!$this->validateOrigin($request)) {
            return $this->forbidden($request, 'Cross-origin requests not allowed');
        }

        // Check session authentication
        if (!Auth::check()) {
            return $this->unauthorized($request, 'Authentication required');
        }

        // Validate CSRF token for non-GET requests
        if (!in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            if (!$this->validateCsrf($request)) {
                return $this->forbidden($request, 'CSRF token mismatch');
            }
        }

        return $next($request);
    }

    private function loadConfig(): void
    {
        $configPath = dirname(__DIR__, 2) . '/config/api.php';
        $this->config = file_exists($configPath) ? require $configPath : [];
    }

    /**
     * Validate the request origin against the whitelist.
     */
    private function validateOrigin(Request $request): bool
    {
        $allowedDomains = $this->config['spa_domains'] ?? [];

        // If no domains configured, reject cross-origin requests.
        // Even in development mode, open-origin with credentials is dangerous
        // because it allows any page to make authenticated requests.
        if (empty($allowedDomains)) {
            $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';

            if ($env === 'local' || $env === 'development') {
                // In development, allow same-origin requests (no Origin header)
                // but reject explicit cross-origin requests.
                $origin = $request->header('origin');
                if ($origin === null) {
                    return true; // Same-origin or direct request
                }

                // Allow localhost origins in development
                $parsed = parse_url($origin);
                $host = $parsed['host'] ?? '';
                if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
                    return true;
                }
            }

            error_log(
                'SpaAuthMiddleware: No spa_domains configured. ' .
                'Set API_SPA_DOMAINS environment variable or configure spa_domains in config/api.php'
            );
            return false;
        }

        // Check Origin header first, then Referer
        $origin = $request->header('origin');
        $referer = $request->header('referer');

        $checkUrl = $origin ?? $referer;

        if ($checkUrl === null) {
            // Modern browsers always send Origin for cross-origin requests.
            // A missing Origin/Referer means either a same-origin request
            // (safe) or a non-browser client. Reject in production to be safe;
            // the CSRF check alone is insufficient for SPA protection.
            $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
            return ! ($env === 'production')

            // In development, allow requests without origin headers
            // (e.g. curl, Postman) for convenience.
            ;
        }

        $parsed = parse_url($checkUrl);
        $host = $parsed['host'] ?? null;

        if ($host === null) {
            return false;
        }

        return in_array($host, $allowedDomains, true);
    }

    /**
     * Validate CSRF token from X-XSRF-TOKEN header.
     */
    private function validateCsrf(Request $request): bool
    {
        // Get CSRF token from header
        $token = $request->header('x-xsrf-token')
            ?? $request->header('x-csrf-token');

        if ($token === null) {
            return false;
        }

        return $this->app->csrf->validate($token);
    }

    /**
     * Return a 401 Unauthorized JSON response.
     */
    private function unauthorized(Request $request, string $detail): Response
    {
        $api = new ApiResponse($this->app->response);
        return $api->unauthorized($detail, $request->uri);
    }

    /**
     * Return a 403 Forbidden JSON response.
     */
    private function forbidden(Request $request, string $detail): Response
    {
        $api = new ApiResponse($this->app->response);
        return $api->forbidden($detail, $request->uri);
    }
}
