<?php

declare(strict_types=1);

namespace Fw\Middleware;

use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Core\Response;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(Request $request, callable $next): Response|string|array
    {
        $response = $next($request);

        if ($response instanceof Response) {
            $response = $this->applySecurityHeaders($response);
        }

        return $response;
    }

    private function applySecurityHeaders(Response $response): Response
    {
        $response = $response
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('X-XSS-Protection', '1; mode=block')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->header('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        if ($this->app->request->isSecure()) {
            $response = $response->header(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        // Only emit CSP for a concrete non-empty string. Null, false,
        // empty string, arrays, and numeric config values are all
        // treated as "do not emit" — stringifying a bool/array/int into
        // a CSP header is worse than no header at all.
        $csp = $this->app->config('app.csp');
        if (is_string($csp) && $csp !== '') {
            $response = $response->header('Content-Security-Policy', $csp);
        }

        return $response;
    }
}
