<?php

declare(strict_types=1);

namespace Fw\Core;

use Fw\Async\Deferred;
use Fw\Bus\Command;
use Fw\Bus\CommandBus;
use Fw\Bus\Query;
use Fw\Bus\QueryBus;
use Fw\Events\Event;
use Fw\Events\EventDispatcher;
use Fw\Support\Arr;
use Fw\Support\Option;
use Fw\Support\Result;
use Fw\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Base Controller for MVC applications.
 *
 * Provides a familiar MVC interface while leveraging the framework's
 * async capabilities, Result types, and architectural patterns underneath.
 *
 * @example
 *     class UserController extends Controller
 *     {
 *         public function show(Request $request, string $id): Response
 *         {
 *             $user = $this->query(new GetUserById(UserId::from($id)))
 *                 ->unwrapOr(null);
 *
 *             if (!$user) {
 *                 return $this->notFound('User not found');
 *             }
 *
 *             return $this->view('users.show', compact('user'));
 *         }
 *
 *         public function store(Request $request): Response
 *         {
 *             $result = $this->dispatch(new CreateUser(
 *                 email: $request->input('email'),
 *                 name: $request->input('name'),
 *             ));
 *
 *             return $result->match(
 *                 fn($user) => $this->redirect("/users/{$user->id}"),
 *                 fn($error) => $this->back()->withErrors($error)
 *             );
 *         }
 *     }
 */
abstract class Controller
{
    protected Application $app;

    protected ?CommandBus $commands = null;

    protected ?QueryBus $queries = null;

    protected ?EventDispatcher $events = null;

    protected string $layout = 'app';

    public function __construct(Application $app)
    {
        $this->app = $app;

        // Get buses from the Application (already wired during bootstrap)
        $this->commands = $app->commands;
        $this->queries = $app->queries;
        $this->events = $app->events;
    }

    // ========================================
    // RESPONSE HELPERS
    // ========================================

    /**
     * Render a view.
     */
    protected function view(string $template, array $data = []): Response
    {
        if ($this->layout) {
            $this->app->view->layout($this->layout);
        }

        $content = $this->app->view->render($template, $data);
        return new Response($content)->contentType('text/html');
    }

    /**
     * Render a cached view (for static or semi-static pages).
     *
     * Caches the rendered output for the specified TTL.
     * Great for pages like /about, /terms, /pricing that rarely change.
     *
     * @param string $template View template name
     * @param array $data View data
     * @param int $ttl Cache TTL in seconds (default: 1 hour)
     */
    protected function cachedView(string $template, array $data = [], int $ttl = 3600): Response
    {
        if ($this->layout) {
            $this->app->view->layout($this->layout);
        }

        $content = $this->app->view->renderCached($template, $data, $ttl);
        return new Response($content)->contentType('text/html');
    }

    /**
     * Stream a view directly to output (for large pages).
     *
     * Reduces memory usage and improves Time-To-First-Byte.
     * Use for large HTML pages or real-time content.
     *
     * Note: Streaming bypasses layouts - include layout in the view itself.
     */
    protected function streamedView(string $template, array $data = []): StreamedResponse
    {
        return $this->app->view->streamed($template, $data);
    }

    /**
     * Return a JSON response.
     */
    protected function json(mixed $data, int $status = 200): Response
    {
        return new Response()
            ->setStatus($status)
            ->header('Content-Type', 'application/json')
            ->setBody(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Redirect to a URL.
     */
    protected function redirect(string $url, int $status = 302): Response
    {
        return new Response()
            ->setStatus($status)
            ->header('Location', $url);
    }

    /**
     * Redirect back to the previous page.
     */
    protected function back(): Response
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        return $this->redirect($referer);
    }

    /**
     * Return a 404 Not Found response.
     */
    protected function notFound(string $message = 'Not Found'): Response
    {
        return new Response($message)
            ->setStatus(404);
    }

    /**
     * Return a 403 Forbidden response.
     */
    protected function forbidden(string $message = 'Forbidden'): Response
    {
        return new Response($message)
            ->setStatus(403);
    }

    /**
     * Return a 400 Bad Request response.
     */
    protected function badRequest(string $message = 'Bad Request'): Response
    {
        return new Response($message)
            ->setStatus(400);
    }

    /**
     * Return a 500 Server Error response.
     */
    protected function serverError(string $message = 'Internal Server Error'): Response
    {
        return new Response($message)
            ->setStatus(500);
    }

    /**
     * Return an empty response with status.
     */
    protected function noContent(int $status = 204): Response
    {
        return new Response()->setStatus($status);
    }

    // ========================================
    // COMMAND/QUERY HELPERS
    // ========================================

    /**
     * Dispatch a command through the command bus.
     *
     * @template T
     * @return Result<T, Throwable>
     */
    protected function dispatch(Command $command): Result
    {
        if ($this->commands === null) {
            return Result::err(new RuntimeException('CommandBus not configured'));
        }

        return $this->commands->dispatch($command);
    }

    /**
     * Dispatch a query through the query bus.
     *
     * @template T
     * @return Result<T, Throwable>
     */
    protected function query(Query $query): Result
    {
        if ($this->queries === null) {
            return Result::err(new RuntimeException('QueryBus not configured'));
        }

        return $this->queries->dispatch($query);
    }

    /**
     * Emit a domain event.
     */
    protected function emit(Event $event): void
    {
        $this->events?->dispatch($event);
    }

    // ========================================
    // ASYNC HELPERS
    // ========================================

    /**
     * Await a Deferred value (suspends the Fiber).
     *
     * Use this when fetching data asynchronously in the controller.
     *
     * @template T
     * @param Deferred<T> $deferred
     * @return T
     */
    protected function await(Deferred $deferred): mixed
    {
        return $deferred->await();
    }

    /**
     * Execute an async database query.
     *
     * @return array<array<string, mixed>>
     */
    protected function dbQuery(string $sql, array $params = []): array
    {
        if ($this->app->db === null) {
            throw new RuntimeException('Database not configured');
        }

        $asyncDb = new \Fw\Async\AsyncDatabase($this->app->db);
        return $this->await($asyncDb->fetchAll($sql, $params));
    }

    /**
     * Execute an async database query for a single row.
     *
     * @return Option<array<string, mixed>>
     */
    protected function dbQueryOne(string $sql, array $params = []): Option
    {
        if ($this->app->db === null) {
            return Option::none();
        }

        $asyncDb = new \Fw\Async\AsyncDatabase($this->app->db);
        $result = $this->await($asyncDb->fetchOne($sql, $params));

        return Option::fromNullable($result);
    }

    // ========================================
    // INPUT HELPERS (using Arr/Str)
    // ========================================

    /**
     * Get validated input from request using dot notation.
     */
    protected function input(Request $request, string $key, mixed $default = null): mixed
    {
        return $request->input($key, $default);
    }

    /**
     * Get only specific keys from input.
     *
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    protected function only(Request $request, array $keys): array
    {
        return $request->only($keys);
    }

    /**
     * Get all input except specific keys.
     *
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    protected function except(Request $request, array $keys): array
    {
        return $request->except($keys);
    }

    /**
     * Check if input has a key.
     */
    protected function has(Request $request, string $key): bool
    {
        return $request->has($key);
    }

    // ========================================
    // VALIDATION HELPERS
    // ========================================

    /**
     * Validate input and return Result.
     *
     * @param array<string, string> $rules Simple validation rules
     * @return Result<array<string, mixed>, array<string, string>>
     */
    protected function validate(Request $request, array $rules): Result
    {
        $data = $request->all();
        $errors = [];

        foreach ($rules as $field => $rule) {
            $value = Arr::get($data, $field);
            $ruleList = explode('|', $rule);

            foreach ($ruleList as $r) {
                $error = $this->checkRule($field, $value, $r);
                if ($error !== null) {
                    $errors[$field] = $error;
                    break;
                }
            }
        }

        if (!empty($errors)) {
            return Result::err($errors);
        }

        return Result::ok(Arr::only($data, array_keys($rules)));
    }

    // ========================================
    // UTILITY HELPERS
    // ========================================

    /**
     * Get the authenticated user (if auth is set up).
     *
     * @return Option<mixed>
     */
    protected function user(): Option
    {
        return Option::fromNullable($_SESSION['user'] ?? null);
    }

    /**
     * Check if user is authenticated.
     */
    protected function isAuthenticated(): bool
    {
        return $this->user()->isSome();
    }

    /**
     * Abort with an exception.
     */
    protected function abort(int $status, string $message = ''): never
    {
        throw new RuntimeException($message ?: "HTTP Error {$status}", $status);
    }

    /**
     * Check a single validation rule.
     */
    private function checkRule(string $field, mixed $value, string $rule): ?string
    {
        $parts = explode(':', $rule);
        $ruleName = $parts[0];
        $param = $parts[1] ?? null;

        return match ($ruleName) {
            'required' => $value === null || $value === '' ? "{$field} is required" : null,
            'email' => !filter_var($value, FILTER_VALIDATE_EMAIL) ? "{$field} must be a valid email" : null,
            'min' => strlen((string) $value) < (int) $param ? "{$field} must be at least {$param} characters" : null,
            'max' => strlen((string) $value) > (int) $param ? "{$field} must be at most {$param} characters" : null,
            'numeric' => !is_numeric($value) ? "{$field} must be numeric" : null,
            'alpha' => !ctype_alpha((string) $value) ? "{$field} must contain only letters" : null,
            'alphanumeric' => !ctype_alnum((string) $value) ? "{$field} must contain only letters and numbers" : null,
            'url' => !filter_var($value, FILTER_VALIDATE_URL) ? "{$field} must be a valid URL" : null,
            'uuid' => !Str::isUuid((string) $value) ? "{$field} must be a valid UUID" : null,
            default => null,
        };
    }
}
