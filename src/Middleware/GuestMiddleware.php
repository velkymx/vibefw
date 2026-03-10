<?php

declare(strict_types=1);

namespace Fw\Middleware;

use Fw\Auth\Auth;
use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Core\Response;

final class GuestMiddleware implements MiddlewareInterface
{
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(Request $request, callable $next): Response|string|array
    {
        if (Auth::check()) {
            return new Response()->redirect('/dashboard');
        }

        return $next($request);
    }
}
