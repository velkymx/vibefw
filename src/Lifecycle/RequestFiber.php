<?php

declare(strict_types=1);

namespace Fw\Lifecycle;

use Fiber;
use Fw\Async\EventLoop;
use Fw\Core\Application;
use Fw\Core\Request;
use Fw\Core\Response;
use Throwable;

/**
 * Wraps a request in a Fiber with lifecycle hooks.
 *
 * RequestFiber manages the execution of a request handler (either a Component
 * or a callable) within a PHP Fiber, enabling async operations through
 * suspension and resumption.
 */
final class RequestFiber
{
    private Fiber $fiber;
    /** @var Application|object */
    private object $app;
    private Request $request;
    private mixed $output = null;
    private ?Throwable $error = null;
    private bool $completed = false;
    private Hook $currentHook = Hook::BOOTING;

    /** @var array<string, mixed> */
    private array $params;

    /**
     * @param Application|object $app Application instance
     * @param Component|callable $handler The request handler
     * @param array<string, mixed> $params Route parameters
     */
    public function __construct(
        object $app,
        Request $request,
        Component|callable $handler,
        array $params = []
    ) {
        $this->app = $app;
        $this->request = $request;
        $this->params = $params;

        $this->fiber = new Fiber(function () use ($handler) {
            try {
                if ($handler instanceof Component) {
                    $this->output = $this->executeComponent($handler);
                } else {
                    $this->output = $this->executeCallable($handler);
                }
            } catch (Throwable $e) {
                $this->error = $e;
            } finally {
                $this->completed = true;
            }
        });
    }

    /**
     * Start the request Fiber.
     */
    public function start(): void
    {
        $this->fiber->start();
    }

    /**
     * Resume the Fiber with a value.
     */
    public function resume(mixed $value = null): void
    {
        if ($this->fiber->isSuspended()) {
            $this->fiber->resume($value);
        }
    }

    /**
     * Throw an exception into the Fiber.
     */
    public function throw(Throwable $e): void
    {
        if ($this->fiber->isSuspended()) {
            $this->fiber->throw($e);
        }
    }

    /**
     * Check if Fiber is suspended.
     */
    public function isSuspended(): bool
    {
        return $this->fiber->isSuspended();
    }

    /**
     * Check if Fiber has started.
     */
    public function isStarted(): bool
    {
        return $this->fiber->isStarted();
    }

    /**
     * Check if Fiber is terminated.
     */
    public function isTerminated(): bool
    {
        return $this->fiber->isTerminated();
    }

    /**
     * Check if the request processing is completed.
     */
    public function isCompleted(): bool
    {
        return $this->completed;
    }

    /**
     * Get the output if completed.
     */
    public function getOutput(): mixed
    {
        return $this->output;
    }

    /**
     * Get the error if failed.
     */
    public function getError(): ?Throwable
    {
        return $this->error;
    }

    /**
     * Get the current lifecycle hook being executed.
     */
    public function getCurrentHook(): Hook
    {
        return $this->currentHook;
    }

    /**
     * Execute a Component with full lifecycle.
     */
    private function executeComponent(Component $component): string
    {
        // Set route parameters on component
        $component->setParams($this->params);

        try {
            // ========================================
            // INITIALIZATION PHASE
            // ========================================
            $this->currentHook = Hook::BOOTING;
            $component->booting();

            $this->currentHook = Hook::BOOTED;
            $component->booted();

            // ========================================
            // REQUEST PHASE
            // ========================================
            $this->currentHook = Hook::BEFORE_REQUEST;
            $component->beforeRequest();

            $this->currentHook = Hook::AFTER_REQUEST;
            $component->afterRequest();

            // ========================================
            // DATA PHASE (async data fetching)
            // ========================================
            $this->currentHook = Hook::BEFORE_FETCH;
            $component->beforeFetch();

            $this->currentHook = Hook::FETCH;
            $component->fetch();  // May suspend here for async I/O

            $this->currentHook = Hook::AFTER_FETCH;
            $component->afterFetch();

            // ========================================
            // RENDER
            // ========================================
            $output = $component->render();

            // ========================================
            // RESPONSE PHASE
            // ========================================
            $this->currentHook = Hook::BEFORE_RESPONSE;
            $component->beforeResponse();

            return $output;

        } catch (Throwable $e) {
            $this->currentHook = Hook::ERROR;
            $component->error($e);
            throw $e;
        } finally {
            // Always call afterResponse, even on error
            $this->currentHook = Hook::AFTER_RESPONSE;
            try {
                $component->afterResponse();
            } catch (Throwable $cleanupError) {
                // Log cleanup error but don't override the original error
                $this->app->log->error('Error in afterResponse cleanup: {message}', [
                    'message' => $cleanupError->getMessage(),
                    'exception' => $cleanupError,
                ]);
            }
        }
    }

    /**
     * Execute a callable handler.
     * For backwards compatibility with traditional route handlers.
     */
    private function executeCallable(callable $handler): mixed
    {
        $result = $handler($this->request, ...$this->params);

        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            $this->app->response->header('Content-Type', 'application/json');
            return json_encode($result, JSON_THROW_ON_ERROR);
        }

        return (string) $result;
    }

    /**
     * Get the underlying Fiber instance.
     */
    public function getFiber(): Fiber
    {
        return $this->fiber;
    }
}
