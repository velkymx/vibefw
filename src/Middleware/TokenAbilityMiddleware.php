<?php

declare(strict_types=1);

namespace Fw\Middleware;

use Fw\Auth\TokenGuard;
use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Http\ApiResponse;

/**
 * Token ability (scope) checking middleware.
 *
 * Validates that the authenticated token has the required abilities.
 * Returns 403 Forbidden if the token lacks required permissions.
 *
 * Usage in routes:
 *   ->middleware('ability:posts:read')
 *   ->middleware('ability:posts:write,posts:delete')
 */
final class TokenAbilityMiddleware implements MiddlewareInterface
{
    private Application $app;

    private string $abilities;

    public function __construct(Application $app, string $abilities = '')
    {
        $this->app = $app;
        $this->abilities = $abilities;
    }

    public function handle(Request $request, callable $next): Response|string|array
    {
        // Ensure user is authenticated via token
        if (!TokenGuard::check()) {
            return $this->forbidden($request, 'Token authentication required');
        }

        // Parse required abilities
        $requiredAbilities = $this->parseAbilities();

        if (empty($requiredAbilities)) {
            // No abilities required, just check authentication
            return $next($request);
        }

        // Check if token has ANY of the required abilities
        foreach ($requiredAbilities as $ability) {
            if (TokenGuard::tokenCan($ability)) {
                return $next($request);
            }
        }

        // Token lacks all required abilities
        return $this->forbidden(
            $request,
            'Token does not have required abilities: ' . implode(', ', $requiredAbilities)
        );
    }

    /**
     * Parse the abilities string into an array.
     */
    private function parseAbilities(): array
    {
        if (empty($this->abilities)) {
            return [];
        }

        return array_map('trim', explode(',', $this->abilities));
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
