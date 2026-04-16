<?php

declare(strict_types=1);

namespace Fw\Aux\Http;

use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Middleware\MiddlewareInterface;

/**
 * Middleware for AUX agent endpoints.
 *
 * Enforces Accept: application/json (or wildcard) and adds X-AUX-Version
 * to all responses so clients can detect the AUX layer version.
 */
final class AgentMiddleware implements MiddlewareInterface
{
    private const AUX_VERSION = '1.0';

    public function handle(Request $request, callable $next): Response|string|array
    {
        $accept = $request->header('Accept', '*/*');

        if (!$this->acceptsJson($accept)) {
            return (new Response(json_encode([
                'type' => 'https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/406',
                'title' => 'Not Acceptable',
                'detail' => 'Agent endpoints require Accept: application/json',
                'status' => 406,
            ], JSON_THROW_ON_ERROR)))
                ->setStatus(406)
                ->header('Content-Type', 'application/json');
        }

        /** @var Response $response */
        $response = $next($request);

        return $response->header('X-AUX-Version', self::AUX_VERSION);
    }

    private function acceptsJson(string $accept): bool
    {
        return str_contains($accept, 'application/json')
            || str_contains($accept, '*/*')
            || str_contains($accept, 'application/*');
    }
}
