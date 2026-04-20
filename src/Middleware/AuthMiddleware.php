<?php

declare(strict_types=1);

namespace Fw\Middleware;

use Fw\Auth\Auth;
use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Core\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(Request $request, callable $next): Response|string|array
    {
        if (!Auth::check()) {
            if ($request->isAjax() || $request->isJson()) {
                return new Response()
                    ->setStatus(401)
                    ->securityHeaders();
            }

            // Validate and store intended URL to prevent open redirect
            $this->storeIntendedUrl($request->uri);

            return new Response()->redirect('/login');
        }

        return $next($request);
    }

    /**
     * Store intended URL in session after validation.
     *
     * Only stores same-origin URLs to prevent open redirect attacks.
     */
    private function storeIntendedUrl(string $url): void
    {
        if ($this->isSafeRedirectUrl($url)) {
            // Session may not be started yet if the 'web'/'csrf' middleware group
            // is not applied to this route — start it explicitly before writing.
            $this->app->initSession();
            $_SESSION['_intended_url'] = $url;
        }
    }

    /**
     * Check if a URL is safe for redirect (same-origin or relative path).
     */
    private function isSafeRedirectUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        // Decode percent-encoded octets until stable so that `%2F%2Fevil.com`
        // (or any layered double-encoding) collapses into the form a browser
        // will ultimately dereference before we apply the prefix checks.
        $decoded = $url;
        for ($i = 0; $i < 3; $i++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        // Protocol-relative URLs (//evil.com) are not safe
        if (str_starts_with($decoded, '//')) {
            return false;
        }

        // Relative paths starting with / are safe
        if (str_starts_with($decoded, '/')) {
            return true;
        }

        // Parse the decoded URL
        $parsed = parse_url($decoded);

        // Block dangerous schemes (javascript:, data:, vbscript:, etc.)
        if (isset($parsed['scheme']) && !in_array($parsed['scheme'], ['http', 'https'], true)) {
            return false;
        }

        // If it has a host, it's external - not safe
        return !isset($parsed['host']);
    }
}
