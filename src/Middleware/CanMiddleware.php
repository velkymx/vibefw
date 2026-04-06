<?php

declare(strict_types=1);

namespace Fw\Middleware;

use Fw\Auth\Auth;
use Fw\Auth\Gate;
use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Core\RequestContext;
use Fw\Core\Response;

/**
 * Authorization Middleware.
 *
 * Usage in routes:
 *   ->middleware('can:edit,post')      - Checks PostPolicy::edit() with route model
 *   ->middleware('can:create,post')    - Checks PostPolicy::create()
 *
 * The second parameter is the model name (lowercase).
 * Route parameters are accessed by name from RouteMatch stored in RequestContext.
 *
 * Example:
 *   $router->put('/posts/{id}', ...)->middleware('can:edit,post');
 *   // Loads Post::find($id) and checks PostPolicy::edit($user, $post)
 *
 *   $router->put('/users/{user}/posts/{post}', ...)->middleware('can:edit,post');
 *   // Loads Post::find($post) and checks PostPolicy::edit($user, $post)
 */
final class CanMiddleware implements MiddlewareInterface
{
    private Application $app;

    private string $ability;

    private string $model;

    public function __construct(Application $app, string $ability = '', string $model = '')
    {
        $this->app = $app;
        $this->ability = $ability;
        $this->model = $model;
    }

    public function handle(Request $request, callable $next): Response|string|array
    {
        if (!Auth::check()) {
            return $this->unauthorized($request);
        }

        $modelClass = $this->resolveModelClass();

        if ($modelClass === null) {
            return $this->forbidden();
        }

        $model = $this->resolveModel($modelClass);
        $target = $model ?? $modelClass;

        if (Gate::denies($this->ability, $target)) {
            return $this->forbidden();
        }

        return $next($request);
    }

    private function resolveModelClass(): ?string
    {
        $modelName = ucfirst($this->model);
        $class = "App\\Models\\{$modelName}";

        return class_exists($class) ? $class : null;
    }

    private function resolveModel(string $class): ?object
    {
        $context = RequestContext::current();

        if ($context === null) {
            return null;
        }

        // Try to find route parameter by name in order of specificity:
        // 1. {model} - e.g., {post} for Post model
        // 2. {model_id} - e.g., {post_id} for Post model
        // 3. {id} - generic fallback
        $paramNames = [
            $this->model,           // e.g., 'post'
            $this->model . '_id',   // e.g., 'post_id'
            'id',                   // generic fallback
        ];

        foreach ($paramNames as $paramName) {
            $value = $context->routeParam($paramName);

            if ($value->isSome()) {
                return $class::find($value->unwrapOr(null))->unwrapOr(null);
            }
        }

        return null;
    }

    private function unauthorized(Request $request): Response
    {
        if ($request->isAjax() || $request->isJson()) {
            return $this->app->response->setStatus(401);
        }

        // Validate intended URL before storing to prevent open redirect
        $this->storeIntendedUrl($request->uri);
        return $this->app->response->redirect('/login');
    }

    private function forbidden(): Response
    {
        return $this->app->response->setStatus(403);
    }

    /**
     * Store intended URL in session after validation.
     *
     * Only stores same-origin URLs to prevent open redirect attacks.
     */
    private function storeIntendedUrl(string $url): void
    {
        if ($this->isSafeRedirectUrl($url)) {
            $_SESSION['_intended_url'] = $url;
        }
    }

    /**
     * Check if a URL is safe for redirect (same-origin or relative path).
     */
    private function isSafeRedirectUrl(string $url): bool
    {
        // Empty URL is not safe
        if ($url === '') {
            return false;
        }

        // Protocol-relative URLs (//evil.com) are not safe
        if (str_starts_with($url, '//')) {
            return false;
        }

        // Relative paths starting with / are safe
        if (str_starts_with($url, '/')) {
            return true;
        }

        // Parse the URL
        $parsed = parse_url($url);

        // Relative URLs without scheme/host are safe
        if (!isset($parsed['scheme']) && !isset($parsed['host'])) {
            return true;
        }

        // If it has a host, it's external - not safe
        return ! (isset($parsed['host']))

        ;
    }
}
