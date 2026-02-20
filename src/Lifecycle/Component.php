<?php

declare(strict_types=1);

namespace Fw\Lifecycle;

use Fw\Async\Deferred;
use Fw\Core\Application;
use Fw\Core\Request;
use Throwable;

/**
 * Base class for async request handlers with lifecycle hooks.
 *
 * Components are the primary way to handle requests in the Fw framework.
 * They provide a structured lifecycle with hooks for initialization,
 * data fetching (async), rendering, and cleanup.
 *
 * Lifecycle order:
 * 1. booting()       - Before services initialized
 * 2. booted()        - After all services ready
 * 3. beforeRequest() - Before processing request
 * 4. afterRequest()  - After request bound/routed
 * 5. beforeFetch()   - Before data fetching
 * 6. fetch()         - Async data fetching (can suspend)
 * 7. afterFetch()    - After data loaded
 * 8. render()        - Generate output
 * 9. beforeResponse()- Before sending response
 * 10. afterResponse()- After response sent, cleanup
 *
 * @example
 * class UserProfilePage extends Component
 * {
 *     private ?array $user = null;
 *
 *     public function fetch(): void
 *     {
 *         $db = new AsyncDatabase($this->app->db);
 *         $this->user = $this->await($db->fetchOne(
 *             'SELECT * FROM users WHERE id = ?',
 *             [$this->request->param('id')]
 *         ));
 *     }
 *
 *     public function render(): string
 *     {
 *         return $this->app->view->render('users/profile', [
 *             'user' => $this->user,
 *         ]);
 *     }
 * }
 */
abstract class Component
{
    /** @var Application|object Application instance (typed as object for testability) */
    protected object $app;
    protected Request $request;

    /** @var array<string, mixed> Data storage for component state */
    protected array $data = [];

    /** @var array<Throwable> Errors collected during lifecycle */
    protected array $errors = [];

    /** @var array<string, mixed> Route parameters */
    protected array $params = [];

    /**
     * @param Application|object $app Application instance
     */
    public function __construct(object $app, Request $request)
    {
        $this->app = $app;
        $this->request = $request;
    }

    /**
     * Set route parameters.
     *
     * @param array<string, mixed> $params
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /**
     * Get a route parameter.
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    // ========================================
    // INITIALIZATION HOOKS
    // ========================================

    /**
     * Called before the component is fully initialized.
     * Services and dependencies are being set up.
     */
    public function booting(): void
    {
        // Override in subclass
    }

    /**
     * Called after the component is initialized.
     * All services are available.
     */
    public function booted(): void
    {
        // Override in subclass
    }

    // ========================================
    // REQUEST HOOKS
    // ========================================

    /**
     * Called before the request is processed.
     * Route is matched but handler not yet executed.
     */
    public function beforeRequest(): void
    {
        // Override in subclass
    }

    /**
     * Called after the request is bound.
     * Parameters are available, ready for data fetching.
     */
    public function afterRequest(): void
    {
        // Override in subclass
    }

    // ========================================
    // DATA HOOKS
    // ========================================

    /**
     * Called before data fetching begins.
     */
    public function beforeFetch(): void
    {
        // Override in subclass
    }

    /**
     * Fetch data asynchronously. This hook can suspend.
     * Use await() for async operations.
     */
    public function fetch(): void
    {
        // Override in subclass
    }

    /**
     * Called after all data has been fetched.
     * All async operations complete, data is ready.
     */
    public function afterFetch(): void
    {
        // Override in subclass
    }

    // ========================================
    // RESPONSE HOOKS
    // ========================================

    /**
     * Called just before the response is sent.
     * Use for final modifications, logging, cleanup.
     */
    public function beforeResponse(): void
    {
        // Override in subclass
    }

    /**
     * Called after the response is sent.
     * Final cleanup: close connections, clear resources.
     */
    public function afterResponse(): void
    {
        // Override in subclass
    }

    // ========================================
    // ERROR HANDLING
    // ========================================

    /**
     * Handle errors during lifecycle.
     * Override to provide custom error handling.
     */
    public function error(Throwable $e): void
    {
        $this->errors[] = $e;
    }

    /**
     * Get all errors that occurred during lifecycle.
     *
     * @return array<Throwable>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Check if any errors occurred.
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    // ========================================
    // RENDER
    // ========================================

    /**
     * Render the response output.
     * Must be implemented by subclasses.
     */
    abstract public function render(): string;

    // ========================================
    // HELPERS
    // ========================================

    /**
     * Helper to await a Deferred value.
     * Suspends the current Fiber until the value is resolved.
     *
     * @throws Throwable If the deferred was rejected
     */
    protected function await(Deferred $deferred): mixed
    {
        return $deferred->await();
    }

    /**
     * Set a data value.
     */
    protected function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * Get a data value.
     */
    protected function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Check if a data key exists.
     */
    protected function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Get all data.
     *
     * @return array<string, mixed>
     */
    protected function all(): array
    {
        return $this->data;
    }

    /**
     * Clear all data.
     */
    protected function clear(): void
    {
        $this->data = [];
    }

    /**
     * Merge data into the component's data store.
     *
     * @param array<string, mixed> $data
     */
    protected function merge(array $data): void
    {
        $this->data = array_merge($this->data, $data);
    }

    /**
     * Render a view with the component's data.
     * Convenience method that passes all component data to the view.
     */
    protected function view(string $template, array $additionalData = []): string
    {
        return $this->app->view->render($template, array_merge($this->data, $additionalData));
    }

    /**
     * Return a JSON response.
     *
     * @param array<string, mixed> $data
     */
    protected function json(array $data): string
    {
        $this->app->response->header('Content-Type', 'application/json');
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * Redirect to a URL.
     * Note: This sets headers but returns empty string for render.
     */
    protected function redirect(string $url, int $status = 302): string
    {
        $this->app->response->setStatus($status);
        $this->app->response->header('Location', $url);
        return '';
    }

    /**
     * Abort the request with an error status.
     *
     * @throws \RuntimeException
     */
    protected function abort(int $status, string $message = ''): never
    {
        throw new \RuntimeException($message ?: "HTTP Error $status", $status);
    }
}
