<?php

declare(strict_types=1);

namespace Fw\Http;

use DateTimeImmutable;
use DateTimeInterface;
use Fw\Core\Response;

/**
 * Standardized JSON API response builder.
 *
 * Provides consistent response formatting following RFC 9457 Problem Details
 * for errors and a structured format for success responses.
 */
final class ApiResponse
{
    private Response $response;

    private array $headers = [];

    private ?string $baseUri = null;

    public function __construct(?Response $response = null)
    {
        $this->response = $response ?? new Response();
    }

    /**
     * Create a new API response instance.
     */
    public static function make(?Response $response = null): self
    {
        return new self($response);
    }

    /**
     * Create a HATEOAS link.
     */
    public static function link(string $href, string $rel = 'self', string $method = 'GET'): array
    {
        return [
            'href' => $href,
            'rel' => $rel,
            'method' => $method,
        ];
    }

    /**
     * Create multiple HATEOAS links.
     */
    public static function links(array $links): array
    {
        $result = [];
        foreach ($links as $rel => $href) {
            if (is_array($href)) {
                $result[] = array_merge(['rel' => $rel], $href);
            } else {
                $result[] = ['href' => $href, 'rel' => $rel];
            }
        }

        return $result;
    }

    /**
     * Set the base URI for error type URLs.
     */
    public function withBaseUri(string $uri): self
    {
        $this->baseUri = rtrim($uri, '/');
        return $this;
    }

    /**
     * Set a response header.
     */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Create a success response with data.
     */
    public function success(mixed $data = null, int $status = 200): Response
    {
        $payload = [
            'data' => $data,
            'meta' => [
                'timestamp' => new DateTimeImmutable()->format(DateTimeInterface::RFC3339),
            ],
        ];

        return $this->response
            ->setStatus($status)
            ->headers($this->headers)
            ->contentType('application/json')
            ->setBody(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Create a success response with a message.
     */
    public function message(string $message, int $status = 200): Response
    {
        return $this->success(['message' => $message], $status);
    }

    /**
     * Create a created response (201).
     */
    public function created(mixed $data = null, ?string $location = null): Response
    {
        if ($location !== null) {
            $this->header('Location', $location);
        }

        return $this->success($data, 201);
    }

    /**
     * Create a no content response (204).
     */
    public function noContent(): Response
    {
        return $this->response
            ->setStatus(204)
            ->headers($this->headers)
            ->setBody('');
    }

    /**
     * Create a paginated response.
     */
    public function paginated(
        array $items,
        int $total,
        int $page,
        int $perPage,
        ?string $path = null
    ): Response {
        $totalPages = (int) ceil($total / $perPage);

        $links = [];
        if ($path !== null) {
            $links = [
                'self' => $path . '?page=' . $page,
                'first' => $path . '?page=1',
                'last' => $path . '?page=' . $totalPages,
            ];

            if ($page > 1) {
                $links['prev'] = $path . '?page=' . ($page - 1);
            }

            if ($page < $totalPages) {
                $links['next'] = $path . '?page=' . ($page + 1);
            }
        }

        $payload = [
            'data' => $items,
            'meta' => [
                'timestamp' => new DateTimeImmutable()->format(DateTimeInterface::RFC3339),
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'total_pages' => $totalPages,
                    'from' => ($page - 1) * $perPage + 1,
                    'to' => min($page * $perPage, $total),
                ],
            ],
        ];

        if (!empty($links)) {
            $payload['links'] = $links;
        }

        return $this->success($payload);
    }

    /**
     * Create an error response following RFC 9457 Problem Details.
     */
    public function error(
        string $title,
        int $status = 400,
        ?string $detail = null,
        ?string $type = null,
        ?string $instance = null,
        array $extensions = []
    ): Response {
        // Generate type URL if not provided
        if ($type === null) {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
            $type = ($this->baseUri ?? 'https://api.example.com') . '/errors/' . $slug;
        }

        $payload = [
            'type' => $type,
            'title' => $title,
            'status' => $status,
        ];

        if ($detail !== null) {
            $payload['detail'] = $detail;
        }

        if ($instance !== null) {
            $payload['instance'] = $instance;
        }

        // Add any extension members
        foreach ($extensions as $key => $value) {
            if (!in_array($key, ['type', 'title', 'status', 'detail', 'instance'], true)) {
                $payload[$key] = $value;
            }
        }

        return $this->response
            ->setStatus($status)
            ->headers($this->headers)
            ->contentType('application/problem+json')
            ->setBody(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Create a 400 Bad Request error.
     */
    public function badRequest(string $detail, ?string $instance = null): Response
    {
        return $this->error('Bad Request', 400, $detail, null, $instance);
    }

    /**
     * Create a 401 Unauthorized error.
     */
    public function unauthorized(string $detail = 'Authentication required', ?string $instance = null): Response
    {
        $this->header('WWW-Authenticate', 'Bearer');
        return $this->error('Unauthorized', 401, $detail, null, $instance);
    }

    /**
     * Create a 403 Forbidden error.
     */
    public function forbidden(string $detail = 'Access denied', ?string $instance = null): Response
    {
        return $this->error('Forbidden', 403, $detail, null, $instance);
    }

    /**
     * Create a 404 Not Found error.
     */
    public function notFound(string $detail = 'Resource not found', ?string $instance = null): Response
    {
        return $this->error('Not Found', 404, $detail, null, $instance);
    }

    /**
     * Create a 422 Unprocessable Entity error with validation errors.
     */
    public function validationError(array $errors, ?string $instance = null): Response
    {
        return $this->error(
            'Validation Failed',
            422,
            'The given data was invalid.',
            null,
            $instance,
            ['errors' => $errors]
        );
    }

    /**
     * Create a 429 Too Many Requests error.
     */
    public function tooManyRequests(int $retryAfter = 60, ?string $instance = null): Response
    {
        $this->header('Retry-After', (string) $retryAfter);
        return $this->error(
            'Too Many Requests',
            429,
            'Rate limit exceeded. Please try again later.',
            null,
            $instance,
            ['retry_after' => $retryAfter]
        );
    }

    /**
     * Create a 500 Internal Server Error.
     */
    public function serverError(string $detail = 'An unexpected error occurred', ?string $instance = null): Response
    {
        return $this->error('Internal Server Error', 500, $detail, null, $instance);
    }

    /**
     * Get the HTTP status code.
     */
    public function getStatus(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * Get the response headers.
     */
    public function getHeaders(): array
    {
        return array_merge($this->response->getHeaders(), $this->headers);
    }

    /**
     * Send the response and exit.
     * @deprecated Use Kernel to emit response
     */
    public function send(array $data): never
    {
        $response = $this->success($data);
        // This is problematic in worker mode, but we keep it for legacy compatibility
        // while marking it as deprecated.
        foreach ($response->getHeaders() as $name => $value) {
            header("$name: $value");
        }
        http_response_code($response->getStatusCode());
        echo $response->getBody();
        exit;
    }
}
