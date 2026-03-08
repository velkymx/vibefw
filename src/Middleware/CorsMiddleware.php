<?php

declare(strict_types=1);

namespace Fw\Middleware;

use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Core\Response;
use InvalidArgumentException;

final class CorsMiddleware implements MiddlewareInterface
{
    private Application $app;

    private array $config;

    public function __construct(Application $app)
    {
        $this->app = $app;

        $defaults = [
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-TOKEN'],
            'exposed_headers' => [],
            'max_age' => 86400,
            'supports_credentials' => false,
        ];

        // Merge in application config so operators can override defaults in
        // config/app.php under the 'cors' key without touching this class.
        $appCors = $app->config('cors', []);
        $this->config = array_merge($defaults, is_array($appCors) ? $appCors : []);
    }

    public function handle(Request $request, callable $next): Response|string|array
    {
        $origin = $request->header('Origin', '');

        if ($request->method === 'OPTIONS') {
            return $this->handlePreflight($origin);
        }

        $response = $next($request);

        return $this->addCorsHeaders($response, $origin);
    }

    private function handlePreflight(string $origin): Response
    {
        $response = $this->app->response->setStatus(204);
        return $this->applyCorsHeaders($response, $origin);
    }

    private function addCorsHeaders(Response|string|array $response, string $origin): Response|string|array
    {
        if ($response instanceof Response) {
            return $this->applyCorsHeaders($response, $origin);
        }

        return $response;
    }

    /**
     * Apply CORS headers to a Response, returning the new immutable instance.
     *
     * Response is immutable — each header() call returns a new object.
     * We must chain the return values.
     */
    private function applyCorsHeaders(Response $response, string $origin): Response
    {
        // Validate configuration: wildcard origin cannot be used with credentials
        // Browsers will reject this combination, so fail early with a clear error
        if (in_array('*', $this->config['allowed_origins'], true) && $this->config['supports_credentials']) {
            throw new InvalidArgumentException(
                'CORS misconfiguration: Cannot use wildcard origin (*) with credentials. ' .
                'Either specify explicit allowed origins or disable supports_credentials.'
            );
        }

        if ($this->isOriginAllowed($origin)) {
            $allowedOrigin = in_array('*', $this->config['allowed_origins'], true)
                ? '*'
                : $origin;

            $response = $response->header('Access-Control-Allow-Origin', $allowedOrigin);
        }

        $response = $response->header(
            'Access-Control-Allow-Methods',
            implode(', ', $this->config['allowed_methods'])
        );

        $response = $response->header(
            'Access-Control-Allow-Headers',
            implode(', ', $this->config['allowed_headers'])
        );

        if (!empty($this->config['exposed_headers'])) {
            $response = $response->header(
                'Access-Control-Expose-Headers',
                implode(', ', $this->config['exposed_headers'])
            );
        }

        $response = $response->header('Access-Control-Max-Age', (string) $this->config['max_age']);

        if ($this->config['supports_credentials']) {
            $response = $response->header('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    private function isOriginAllowed(string $origin): bool
    {
        if (empty($origin)) {
            return false;
        }

        if (in_array('*', $this->config['allowed_origins'], true)) {
            return true;
        }

        // Case-insensitive comparison — domain names are case-insensitive
        $lowerOrigin = strtolower($origin);
        foreach ($this->config['allowed_origins'] as $allowed) {
            if (strtolower($allowed) === $lowerOrigin) {
                return true;
            }
        }

        return false;
    }
}
