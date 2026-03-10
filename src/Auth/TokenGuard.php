<?php

declare(strict_types=1);

namespace Fw\Auth;

use Fw\Core\Request;
use Fw\Core\RequestContext;
use Fw\Model\Model;

/**
 * Stateless token authentication guard.
 *
 * Handles authentication via Bearer tokens in the Authorization header.
 * Used for API authentication where session-based auth is not appropriate.
 *
 * Uses RequestContext for request-scoped state to prevent authentication
 * leaking between concurrent requests in worker/fiber mode.
 */
final class TokenGuard
{
    private const string CONTEXT_USER_KEY = '_token_guard_user';
    private const string CONTEXT_TOKEN_KEY = '_token_guard_token';

    /**
     * Attempt to authenticate via Bearer token.
     *
     * Returns the authenticated user or null if authentication fails.
     */
    public static function authenticate(Request $request): ?Model
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return null;
        }

        return self::authenticateToken($token);
    }

    /**
     * Authenticate using a plaintext token string.
     */
    public static function authenticateToken(string $plainTextToken): ?Model
    {
        $accessToken = ApiToken::find($plainTextToken);

        if ($accessToken === null) {
            return null;
        }

        // Update last used timestamp
        $accessToken->touchLastUsed();

        // Get the user via relationship
        $user = $accessToken->user()->get()->unwrapOr(null);

        if ($user === null) {
            return null;
        }

        // Store in RequestContext for request-scoped access
        self::setContextUser($user);
        self::setContextToken($accessToken);

        return $user;
    }

    /**
     * Check if a user is authenticated via token.
     */
    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * Get the currently authenticated user.
     */
    public static function user(): ?Model
    {
        return self::getContextUser();
    }

    /**
     * Get the current access token.
     */
    public static function currentToken(): ?Model
    {
        return self::getContextToken();
    }

    /**
     * Check if the current token has a specific ability.
     */
    public static function tokenCan(string $ability): bool
    {
        $token = self::currentToken();

        if ($token === null) {
            return false;
        }

        if (!method_exists($token, 'can')) {
            return false;
        }

        return $token->can($ability);
    }

    /**
     * Check if the current token cannot perform an ability.
     */
    public static function tokenCannot(string $ability): bool
    {
        return !self::tokenCan($ability);
    }

    /**
     * Get the abilities of the current token.
     */
    public static function tokenAbilities(): array
    {
        $token = self::currentToken();

        if ($token === null) {
            return [];
        }

        return $token->abilities ?? [];
    }

    /**
     * Get the user ID from the current token (if authenticated).
     */
    public static function id(): string|int|null
    {
        return self::user()?->id;
    }

    /**
     * Clear the current authentication state.
     */
    public static function logout(): void
    {
        self::clearContextUser();
        self::clearContextToken();
    }

    /**
     * Set the authenticated user and token manually.
     *
     * Useful for testing or special authentication flows.
     */
    public static function setUser(Model $user, ?Model $token = null): void
    {
        self::setContextUser($user);
        if ($token !== null) {
            self::setContextToken($token);
        }
    }

    /**
     * Clear the authentication state for the current request.
     *
     * Call this between requests in worker mode to prevent user leakage.
     */
    public static function clearRequestState(): void
    {
        self::clearContextUser();
        self::clearContextToken();
    }

    /**
     * Get user from RequestContext (request-scoped).
     */
    private static function getContextUser(): ?Model
    {
        $context = RequestContext::current();
        if ($context === null) {
            return null;
        }

        $user = $context->get(self::CONTEXT_USER_KEY)->unwrapOr(null);
        return $user instanceof Model ? $user : null;
    }

    /**
     * Set user in RequestContext (request-scoped).
     */
    private static function setContextUser(Model $user): void
    {
        RequestContext::current()?->set(self::CONTEXT_USER_KEY, $user);
    }

    /**
     * Clear user from RequestContext.
     */
    private static function clearContextUser(): void
    {
        RequestContext::current()?->forget(self::CONTEXT_USER_KEY);
    }

    /**
     * Get token from RequestContext (request-scoped).
     */
    private static function getContextToken(): ?Model
    {
        $context = RequestContext::current();
        if ($context === null) {
            return null;
        }

        $token = $context->get(self::CONTEXT_TOKEN_KEY)->unwrapOr(null);
        return $token instanceof Model ? $token : null;
    }

    /**
     * Set token in RequestContext (request-scoped).
     */
    private static function setContextToken(Model $token): void
    {
        RequestContext::current()?->set(self::CONTEXT_TOKEN_KEY, $token);
    }

    /**
     * Clear token from RequestContext.
     */
    private static function clearContextToken(): void
    {
        RequestContext::current()?->forget(self::CONTEXT_TOKEN_KEY);
    }
}
