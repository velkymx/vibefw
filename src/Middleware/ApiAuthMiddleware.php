<?php

declare(strict_types=1);

namespace Fw\Middleware;

use Fw\Auth\TokenGuard;
use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Http\ApiResponse;

/**
 * API authentication middleware.
 *
 * Validates Bearer tokens from the Authorization header.
 * Returns JSON 401 responses following RFC 9457 Problem Details.
 * Does not use sessions or redirects.
 */
final class ApiAuthMiddleware implements MiddlewareInterface
{
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(Request $request, callable $next): Response|string|array
    {
        // Check for Bearer token
        $token = $request->bearerToken();

        if ($token === null) {
            return $this->unauthorized($request, 'No API token provided');
        }

        // Attempt token authentication
        $user = TokenGuard::authenticate($request);

        if ($user === null) {
            return $this->unauthorized($request, 'Invalid or expired API token');
        }

        return $next($request);
    }

    /**
     * Return a 401 Unauthorized JSON response.
     */
    private function unauthorized(Request $request, string $detail): Response
    {
        $api = new ApiResponse($this->app->response);
        return $api->unauthorized($detail, $request->uri);
    }
}
