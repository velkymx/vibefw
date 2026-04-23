<?php

declare(strict_types=1);

namespace Fw\Auth;

use Fw\Auth\Contracts\RevocableTokenInterface;
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

        // Enforce the token model contract BEFORE invoking any
        // contract-specific method. Misconfigured apps (setTokenModel()
        // accepts any class string) previously crashed with a fatal
        // "call to undefined method touchLastUsed()" instead of failing
        // authentication cleanly.
        if (!$accessToken instanceof RevocableTokenInterface) {
            return null;
        }

        // user() is a relationship declared by the token model, not the
        // interface — keep it out of the contract to avoid dragging the
        // Model relation classes into Fw\Auth\Contracts. A misconfigured
        // model without that relation decays to a null auth result.
        if (!method_exists($accessToken, 'user')) {
            return null;
        }

        $accessToken->touchLastUsed();

        $user = $accessToken->user()->get()->unwrapOr(null);

        if ($user === null) {
            return null;
        }

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
    public static function currentToken(): ?RevocableTokenInterface
    {
        return self::getContextToken();
    }

    /**
     * Check if the current token has a specific ability.
     */
    public static function tokenCan(string $ability): bool
    {
        return self::currentToken()?->can($ability) ?? false;
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
     *
     * Delegates to the token model's `abilities` attribute; returns [] when unauthenticated.
     *
     * @return list<string>
     */
    public static function tokenAbilities(): array
    {
        $token = self::currentToken();
        if (!$token instanceof Model) {
            return [];
        }

        $raw = $token->getAttribute('abilities')->unwrapOr(null);
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, 'is_string'));
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
    public static function setUser(Model $user, (Model&RevocableTokenInterface)|null $token = null): void
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
    private static function getContextToken(): ?RevocableTokenInterface
    {
        $context = RequestContext::current();
        if ($context === null) {
            return null;
        }

        $token = $context->get(self::CONTEXT_TOKEN_KEY)->unwrapOr(null);
        return $token instanceof RevocableTokenInterface ? $token : null;
    }

    /**
     * Set token in RequestContext (request-scoped).
     */
    private static function setContextToken(Model&RevocableTokenInterface $token): void
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
