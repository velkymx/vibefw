<?php

declare(strict_types=1);

namespace Fw\Lifecycle;

/**
 * Enum defining all lifecycle stages.
 *
 * Each hook represents a specific point in the request lifecycle
 * where custom logic can be executed.
 */
enum Hook: string
{
    /**
     * Get all hooks in lifecycle order.
     *
     * @return array<Hook>
     */
    public static function inOrder(): array
    {
        return [
            self::BOOTING,
            self::BOOTED,
            self::BEFORE_REQUEST,
            self::AFTER_REQUEST,
            self::BEFORE_FETCH,
            self::FETCH,
            self::AFTER_FETCH,
            self::BEFORE_RESPONSE,
            self::AFTER_RESPONSE,
        ];
    }

    /**
     * Get the method name corresponding to this hook.
     */
    public function getMethodName(): string
    {
        return $this->value;
    }

    /**
     * Check if this hook is in the initialization phase.
     */
    public function isInitializationHook(): bool
    {
        return match ($this) {
            self::BOOTING, self::BOOTED => true,
            default => false,
        };
    }

    /**
     * Check if this hook is in the request phase.
     */
    public function isRequestHook(): bool
    {
        return match ($this) {
            self::BEFORE_REQUEST, self::AFTER_REQUEST => true,
            default => false,
        };
    }

    /**
     * Check if this hook is in the data phase.
     */
    public function isDataHook(): bool
    {
        return match ($this) {
            self::BEFORE_FETCH, self::FETCH, self::AFTER_FETCH => true,
            default => false,
        };
    }

    /**
     * Check if this hook is in the response phase.
     */
    public function isResponseHook(): bool
    {
        return match ($this) {
            self::BEFORE_RESPONSE, self::AFTER_RESPONSE => true,
            default => false,
        };
    }

    /**
     * Check if this hook can cause Fiber suspension.
     */
    public function canSuspend(): bool
    {
        return $this === self::FETCH;
    }
    // ========================================
    // INITIALIZATION HOOKS
    // ========================================

    /**
     * Before services initialized.
     * Use for early setup, before the component is fully ready.
     */
    case BOOTING = 'booting';

    /**
     * After all services ready.
     * Component is fully initialized and ready to process.
     */
    case BOOTED = 'booted';

    // ========================================
    // REQUEST HOOKS
    // ========================================

    /**
     * Before processing request.
     * Route is matched but handler not yet executed.
     */
    case BEFORE_REQUEST = 'beforeRequest';

    /**
     * After request bound/routed.
     * Parameters are available, ready for data fetching.
     */
    case AFTER_REQUEST = 'afterRequest';

    // ========================================
    // DATA HOOKS (async phase)
    // ========================================

    /**
     * Before data fetching begins.
     * Prepare for async operations.
     */
    case BEFORE_FETCH = 'beforeFetch';

    /**
     * Async data fetching (can suspend).
     * This is where Fiber suspension typically occurs.
     */
    case FETCH = 'fetch';

    /**
     * After data loaded.
     * All async operations complete, data is ready for render.
     */
    case AFTER_FETCH = 'afterFetch';

    // ========================================
    // RESPONSE HOOKS
    // ========================================

    /**
     * Before sending response.
     * Use for final modifications, logging, cleanup before send.
     */
    case BEFORE_RESPONSE = 'beforeResponse';

    /**
     * After response sent, cleanup.
     * Final cleanup: close connections, clear resources.
     */
    case AFTER_RESPONSE = 'afterResponse';

    // ========================================
    // ERROR HOOK
    // ========================================

    /**
     * Error handling hook.
     * Called when an exception occurs during lifecycle.
     */
    case ERROR = 'error';
}
