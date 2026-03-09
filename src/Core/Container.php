<?php

declare(strict_types=1);

namespace Fw\Core;

use Closure;
use Fiber;
use Fw\Support\Option;
use Fw\Support\ResettableInterface;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use stdClass;
use Throwable;
use WeakMap;

/**
 * Simple dependency injection container.
 */
final class Container
{
    private static ?self $instance = null;

    /**
     * Guard flag for fiber-safe singleton initialization.
     */
    private static bool $initializing = false;

    /** @var array<class-string, Closure|class-string> */
    private array $bindings = [];

    /**
     * Singleton instances scoped to the current Fiber.
     * Uses WeakMap<Fiber, array> so instances are automatically GC'd when the
     * Fiber is collected, preventing spl_object_id reuse from leaking state
     * between fibers and avoiding memory accumulation in worker mode.
     * @var WeakMap<Fiber, array<class-string, object>>|null
     */
    private ?WeakMap $fiberInstances = null;

    /**
     * Singleton instances for the global (non-Fiber) context.
     * @var array<class-string, object>
     */
    private array $mainInstances = [];

    /**
     * Global instances (shared across all Fibers).
     * @var array<class-string, object>
     */
    private array $globalInstances = [];

    /**
     * Fast-path cache for resolved global singletons.
     * @var array<class-string, object>
     */
    private array $resolvedGlobalInstances = [];

    /** @var array<class-string, bool> */
    private array $singletons = [];

    /** @var array<class-string, bool> */
    private array $globals = [];

    /**
     * Cached reflection metadata for constructor parameters.
     * @var array<class-string, array<int, array{name: string, type: ?string, builtin: bool, nullable: bool, hasDefault: bool, default: mixed}>|null>
     */
    private array $reflectionCache = [];

    /**
     * Classes currently being reflected (Fiber concurrency guard).
     * @var array<class-string, int>
     */
    private array $reflectionInitializing = [];

    /**
     * Get the singleton container instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        if (self::$initializing) {
            $spins = 0;
            while (self::$initializing && self::$instance === null && $spins < 1000) {
                if (Fiber::getCurrent() !== null) {
                    Fiber::suspend();
                } else {
                    usleep(100);
                }
                $spins++;
            }
            if (self::$instance !== null) {
                return self::$instance;
            }
            // If we exhausted the spin budget and still have no instance, the
            // initialising fiber likely crashed. Throw rather than silently
            // creating a second, partially-configured container.
            throw new RuntimeException(
                'Container initialisation deadlock: another context started initialising ' .
                'the container but did not complete within the spin budget. ' .
                'This usually means the bootstrapping Fiber threw an uncaught exception.'
            );
        }

        self::$initializing = true;
        try {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        } finally {
            self::$initializing = false;
        }
    }

    /**
     * Set the singleton instance.
     */
    public static function setInstance(?self $container): void
    {
        self::$instance = $container;
    }

    /**
     * Reset the singleton instance (useful for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Bind an abstract to a concrete implementation.
     */
    public function bind(string $abstract, Closure|string|null $concrete = null): self
    {
        $this->bindings[$abstract] = $concrete ?? $abstract;
        return $this;
    }

    /**
     * Bind a singleton (only instantiated once).
     */
    public function singleton(string $abstract, Closure|string|null $concrete = null, bool $global = false): self
    {
        $this->bind($abstract, $concrete);
        $this->singletons[$abstract] = true;
        if ($global) {
            $this->globals[$abstract] = true;
        }
        return $this;
    }

    /**
     * Register an existing instance.
     */
    public function instance(string $abstract, object $instance, bool $global = false): self
    {
        if ($global || ($this->globals[$abstract] ?? false)) {
            $this->globalInstances[$abstract] = $instance;
            $this->resolvedGlobalInstances[$abstract] = $instance;
        } else {
            $this->setFiberInstance($abstract, $instance);
        }
        return $this;
    }

    /**
     * Check if a binding exists.
     */
    public function has(string $abstract): bool
    {
        return isset($this->resolvedGlobalInstances[$abstract]) ||
               isset($this->globalInstances[$abstract]) ||
               $this->hasFiberInstance($abstract) ||
               isset($this->bindings[$abstract]);
    }

    /**
     * Resolve an instance from the container.
     */
    public function get(string $abstract): object
    {
        // 1. Fast-path: check resolved global singletons first
        if (isset($this->resolvedGlobalInstances[$abstract])) {
            return $this->resolvedGlobalInstances[$abstract];
        }

        // 2. Check global instances
        if (isset($this->globalInstances[$abstract])) {
            if ($this->globals[$abstract] ?? false) {
                $this->resolvedGlobalInstances[$abstract] = $this->globalInstances[$abstract];
            }
            return $this->globalInstances[$abstract];
        }

        // 3. Check Fiber-local instances
        $fiberInstance = $this->getFiberInstance($abstract);
        if ($fiberInstance !== null) {
            return $fiberInstance;
        }

        // Resolve the concrete binding
        $concrete = $this->bindings[$abstract] ?? $abstract;

        if ($concrete instanceof Closure) {
            $object = $concrete($this);
        } else {
            if (!class_exists($concrete)) {
                throw new InvalidArgumentException("Class {$concrete} does not exist");
            }
            $object = $this->buildClass($concrete);
        }

        // Cache if singleton
        if (isset($this->singletons[$abstract])) {
            if ($this->globals[$abstract] ?? false) {
                $this->globalInstances[$abstract] = $object;
                $this->resolvedGlobalInstances[$abstract] = $object;
            } else {
                $this->setFiberInstance($abstract, $object);
            }
        }

        return $object;
    }

    public function tryGet(string $abstract): Option
    {
        try {
            return Option::some($this->get($abstract));
        } catch (Throwable) {
            return Option::none();
        }
    }

    public function call(callable|array $callback, array $parameters = []): mixed
    {
        if (is_array($callback)) {
            if (count($callback) !== 2 || !is_string($callback[1])) {
                throw new InvalidArgumentException('Array callback must be [class-or-object, method-name]');
            }
            [$class, $method] = $callback;
            if (is_string($class)) {
                $class = $this->get($class);
            }
            $reflector = new ReflectionMethod($class, $method);
            $callback = [$class, $method];
        } else {
            $reflector = new ReflectionFunction($callback);
        }

        $dependencies = [];
        foreach ($reflector->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $parameters)) {
                $dependencies[] = $parameters[$name];
                continue;
            }

            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $dependencies[] = $this->get($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
            } elseif ($type?->allowsNull()) {
                $dependencies[] = null;
            } else {
                throw new InvalidArgumentException("Cannot resolve parameter \${$name}");
            }
        }

        return $callback(...$dependencies);
    }

    public function make(string $abstract, array $parameters = []): object
    {
        $concrete = $this->bindings[$abstract] ?? $abstract;
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }

        $reflector = new ReflectionClass($concrete);
        $constructor = $reflector->getConstructor();
        if ($constructor === null) {
            return new $concrete();
        }

        $dependencies = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $parameters)) {
                $dependencies[] = $parameters[$name];
                continue;
            }

            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $dependencies[] = $this->get($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
            } elseif ($type?->allowsNull()) {
                $dependencies[] = null;
            } else {
                throw new InvalidArgumentException("Cannot resolve parameter \${$name} in {$concrete}");
            }
        }

        return $reflector->newInstanceArgs($dependencies);
    }

    public function flush(int|string|null $contextKey = null): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber !== null && $this->fiberInstances !== null) {
            unset($this->fiberInstances[$fiber]);
        } else {
            $this->mainInstances = [];
        }
    }

    public function flushGlobal(): void
    {
        $this->globalInstances = [];
        $this->resolvedGlobalInstances = [];
    }

    public function flushAll(): void
    {
        $this->fiberInstances = null;
        $this->mainInstances = [];
    }

    public function clearReflectionCache(): void
    {
        $this->reflectionCache = [];
        $this->reflectionInitializing = [];
    }

    public function resetForWorker(): void
    {
        $this->flushAll();
        $this->flushGlobal();
        $this->clearReflectionCache();
    }

    public function getResettables(): array
    {
        $resettables = [];

        $fiber = Fiber::getCurrent();
        $fiberData = null;
        if ($fiber !== null && $this->fiberInstances !== null && isset($this->fiberInstances[$fiber])) {
            $fiberData = $this->fiberInstances[$fiber];
        } elseif ($fiber === null) {
            $fiberData = $this->mainInstances;
        }

        if ($fiberData !== null) {
            foreach ($fiberData as $instance) {
                if ($instance instanceof ResettableInterface) {
                    $resettables[] = $instance;
                }
            }
        }

        foreach ($this->globalInstances as $instance) {
            if ($instance instanceof ResettableInterface) {
                $resettables[] = $instance;
            }
        }

        return $resettables;
    }

    public function resolver(): Closure
    {
        return fn (string $class) => $this->get($class);
    }

    private function getFiberInstance(string $abstract): ?object
    {
        $fiber = Fiber::getCurrent();
        if ($fiber !== null) {
            if ($this->fiberInstances !== null && isset($this->fiberInstances[$fiber][$abstract])) {
                return $this->fiberInstances[$fiber][$abstract];
            }
            return null;
        }
        return $this->mainInstances[$abstract] ?? null;
    }

    private function setFiberInstance(string $abstract, object $instance): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber !== null) {
            $this->fiberInstances ??= new WeakMap();
            if (!isset($this->fiberInstances[$fiber])) {
                $this->fiberInstances[$fiber] = [];
            }
            $data = $this->fiberInstances[$fiber];
            $data[$abstract] = $instance;
            $this->fiberInstances[$fiber] = $data;
        } else {
            $this->mainInstances[$abstract] = $instance;
        }
    }

    private function hasFiberInstance(string $abstract): bool
    {
        return $this->getFiberInstance($abstract) !== null;
    }

    private function buildClass(string $concrete): object
    {
        $params = $this->getReflectionCache($concrete);
        if ($params === null) {
            return new $concrete();
        }

        $dependencies = [];
        foreach ($params as $param) {
            if ($param['type'] === null || $param['builtin']) {
                if ($param['hasDefault']) {
                    $dependencies[] = $param['default'];
                    continue;
                }
                throw new InvalidArgumentException("Cannot resolve parameter \${$param['name']} in {$concrete}");
            }

            try {
                $dependencies[] = $this->get($param['type']);
            } catch (Throwable $e) {
                if ($param['hasDefault']) {
                    $dependencies[] = $param['default'];
                } elseif ($param['nullable']) {
                    $dependencies[] = null;
                } else {
                    throw $e;
                }
            }
        }

        return new $concrete(...$dependencies);
    }

    private function getReflectionCache(string $concrete): ?array
    {
        if (array_key_exists($concrete, $this->reflectionCache)) {
            return $this->reflectionCache[$concrete];
        }

        $initToken = spl_object_id(new stdClass());
        $spins = 0;
        while (true) {
            if (array_key_exists($concrete, $this->reflectionCache)) {
                return $this->reflectionCache[$concrete];
            }
            if (!isset($this->reflectionInitializing[$concrete])) {
                $this->reflectionInitializing[$concrete] = $initToken;
                break;
            }
            if (++$spins > 1000) {
                throw new RuntimeException(
                    "Container reflection deadlock: another context started reflecting {$concrete} but did not complete. " .
                    'This usually means the initialising Fiber threw an uncaught exception.'
                );
            }
            if (Fiber::getCurrent() !== null) {
                Fiber::suspend();
            } else {
                usleep(100);
            }
        }

        try {
            $reflector = new ReflectionClass($concrete);
            if (!$reflector->isInstantiable()) {
                throw new InvalidArgumentException("Class {$concrete} is not instantiable");
            }

            $constructor = $reflector->getConstructor();
            if ($constructor === null) {
                $this->reflectionCache[$concrete] = null;
                return null;
            }

            $params = [];
            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();
                $paramInfo = [
                    'name' => $parameter->getName(),
                    'type' => null,
                    'builtin' => false,
                    'nullable' => false,
                    'hasDefault' => $parameter->isDefaultValueAvailable(),
                    'default' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                ];

                if ($type instanceof ReflectionNamedType) {
                    $paramInfo['type'] = $type->getName();
                    $paramInfo['builtin'] = $type->isBuiltin();
                    $paramInfo['nullable'] = $type->allowsNull();
                }
                $params[] = $paramInfo;
            }

            $this->reflectionCache[$concrete] = $params;
            return $params;
        } finally {
            if (($this->reflectionInitializing[$concrete] ?? null) === $initToken) {
                unset($this->reflectionInitializing[$concrete]);
            }
        }
    }
}
