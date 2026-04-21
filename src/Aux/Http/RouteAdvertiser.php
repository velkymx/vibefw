<?php

declare(strict_types=1);

namespace Fw\Aux\Http;

use Fw\Core\Router;

final readonly class RouteAdvertiser
{
    public function __construct(private Router $router) {}

    /**
     * @return list<array{name: string, method: string, path: string, handler: string, middleware: list<string>}>
     */
    public function advertise(): array
    {
        $pathToName = array_flip($this->router->getNamedRoutes());

        $out = [];
        foreach ($this->router->getRoutes() as $method => $routes) {
            foreach ($routes as $route) {
                $name = $pathToName[$route['path']] ?? null;
                if ($name === null) {
                    continue;
                }

                $out[] = [
                    'name' => $name,
                    'method' => $method,
                    'path' => $route['path'],
                    'handler' => $this->summarizeHandler($route['handler']),
                    'middleware' => $this->summarizeMiddleware($route['middleware']),
                ];
            }
        }

        return $out;
    }

    private function summarizeHandler(mixed $handler): string
    {
        if (is_array($handler) && count($handler) === 2) {
            [$class, $action] = $handler;
            $class = is_object($class) ? $class::class : (string) $class;
            return $class . '@' . (string) $action;
        }

        if (is_string($handler)) {
            return $handler;
        }

        if (is_object($handler)) {
            return $handler::class;
        }

        return 'callable';
    }

    /**
     * @param array<mixed> $middleware
     * @return list<string>
     */
    private function summarizeMiddleware(array $middleware): array
    {
        return array_values(array_map(
            fn (mixed $m): string => match (true) {
                is_string($m) => $m,
                is_object($m) => $m::class,
                default => 'callable',
            },
            $middleware,
        ));
    }
}
