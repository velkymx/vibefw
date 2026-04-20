<?php

declare(strict_types=1);

namespace Fw\Aux\Http;

use Fw\Aux\AgentAccessible;
use Fw\Core\Router;
use ReflectionMethod;

final readonly class AgentAccessibleScanner
{
    public function __construct(private Router $router) {}

    /**
     * @return array<string, list<array{name: string, method: string, path: string}>>
     */
    public function scan(): array
    {
        $pathToName = array_flip($this->router->getNamedRoutes());
        $map = [];

        foreach ($this->router->getRoutes() as $httpMethod => $routes) {
            foreach ($routes as $route) {
                $name = $pathToName[$route['path']] ?? null;
                if ($name === null) {
                    continue;
                }

                foreach ($this->extractTools($route['handler']) as $toolName) {
                    $map[$toolName][] = [
                        'name' => $name,
                        'method' => $httpMethod,
                        'path' => $route['path'],
                    ];
                }
            }
        }

        return $map;
    }

    /**
     * @return list<array{name: string, method: string, path: string}>
     */
    public function forTool(string $tool): array
    {
        return $this->scan()[$tool] ?? [];
    }

    /**
     * @return list<string>
     */
    private function extractTools(mixed $handler): array
    {
        if (!is_array($handler) || count($handler) !== 2) {
            return [];
        }

        [$class, $action] = $handler;
        $class = is_object($class) ? $class::class : (string) $class;

        if (!is_string($class) || !class_exists($class) || !method_exists($class, (string) $action)) {
            return [];
        }

        $reflection = new ReflectionMethod($class, (string) $action);

        return array_map(
            fn(\ReflectionAttribute $a): string => $a->newInstance()->tool,
            $reflection->getAttributes(AgentAccessible::class),
        );
    }
}
