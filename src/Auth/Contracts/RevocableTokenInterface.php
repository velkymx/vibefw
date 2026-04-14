<?php

declare(strict_types=1);

namespace Fw\Auth\Contracts;

/**
 * Contract for API token models.
 *
 * TokenGuard stores and returns tokens typed to this interface, allowing
 * callers to safely invoke token-specific methods (revoke, can, etc.)
 * without coupling to a concrete model class.
 */
interface RevocableTokenInterface
{
    /**
     * Revoke (delete) this token.
     */
    public function revoke(): bool;

    /**
     * Check whether this token grants the given ability.
     */
    public function can(string $ability): bool;

    /**
     * Check whether this token has expired.
     */
    public function isExpired(): bool;

    /**
     * Record the current timestamp as the token's last-used time.
     */
    public function touchLastUsed(): void;
}
