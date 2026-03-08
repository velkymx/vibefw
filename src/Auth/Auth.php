<?php

declare(strict_types=1);

namespace Fw\Auth;

use Fw\Core\RequestContext;
use Fw\Model\Model;
use RuntimeException;

/**
 * Authentication manager.
 *
 * Uses RequestContext for request-scoped user state to prevent
 * authentication leaking between concurrent requests in worker mode.
 *
 * Configure the user model class via Auth::setUserModel() if not
 * using the default App\Models\User.
 */
final class Auth
{
    private const string SESSION_KEY = '_auth_user_id';
    private const string REMEMBER_COOKIE = 'remember_token';
    private const int REMEMBER_DURATION = 60 * 60 * 24 * 30; // 30 days
    private const string CONTEXT_USER_KEY = '_auth_user';

    /**
     * The user model class. Must have findByEmail() and verifyPassword() methods.
     *
     * @var class-string<Model>
     */
    private static string $userModel = 'App\\Models\\User';

    /**
     * Dummy hash for timing-safe comparison when user doesn't exist.
     * This prevents user enumeration via timing attacks.
     * Generated with password_hash('dummy', PASSWORD_DEFAULT).
     */
    private const string DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    /**
     * Dummy SHA256 hash for timing-safe remember token comparison.
     * Used when user's remember_token is null to ensure constant-time comparison.
     * 64 hex characters (256 bits) matching SHA256 output.
     */
    private const string DUMMY_SHA256_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Minimum required length for APP_KEY (32 bytes = 64 hex chars or 44 base64 chars).
     */
    private const int MIN_KEY_LENGTH = 32;

    /**
     * Set the user model class used for authentication.
     *
     * @param class-string<Model> $modelClass
     */
    public static function setUserModel(string $modelClass): void
    {
        self::$userModel = $modelClass;
    }

    /**
     * Attempt to authenticate a user with credentials.
     *
     * Uses constant-time comparison even when user doesn't exist
     * to prevent user enumeration via timing attacks.
     */
    public static function attempt(string $email, string $password, bool $remember = false): bool
    {
        $userOption = (self::$userModel)::findByEmail($email);

        if ($userOption->isNone()) {
            // Timing attack mitigation: always perform password verification
            // even when user doesn't exist, using a dummy hash
            // @phpstan-ignore function.resultUnused (intentional for constant-time execution)
            $_ = password_verify($password, self::DUMMY_HASH);
            return false;
        }

        $user = $userOption->unwrap();

        if (!$user->verifyPassword($password)) {
            return false;
        }

        self::login($user, $remember);

        return true;
    }

    /**
     * Log in a user.
     *
     * Regenerates both session ID and CSRF token to prevent
     * session fixation and CSRF token fixation attacks.
     */
    public static function login(Model $user, bool $remember = false): void
    {
        session_regenerate_id(true);

        // Regenerate CSRF token on login to prevent CSRF token fixation attacks
        // An attacker who knows pre-auth CSRF token cannot use it post-auth
        self::regenerateCsrfToken();

        $_SESSION[self::SESSION_KEY] = $user->id;
        self::setContextUser($user);

        if ($remember) {
            self::setRememberToken($user);
        }
    }

    /**
     * Log out the current user.
     */
    public static function logout(): void
    {
        // Clear remember token BEFORE clearing the context user — clearRememberToken()
        // calls self::user() internally and will return null if context is gone first.
        if (isset($_COOKIE[self::REMEMBER_COOKIE])) {
            self::clearRememberToken();
            setcookie(self::REMEMBER_COOKIE, '', time() - 3600, '/', '', self::isHttps(), true);
        }

        self::clearContextUser();
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }

    /**
     * Check if a user is authenticated.
     */
    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * Check if the current user is a guest.
     */
    public static function guest(): bool
    {
        return !self::check();
    }

    /**
     * Get the currently authenticated user.
     *
     * Uses RequestContext for request-scoped storage to prevent
     * user state from leaking between concurrent requests.
     */
    public static function user(): ?Model
    {
        // Check RequestContext first (request-scoped cache)
        $contextUser = self::getContextUser();
        if ($contextUser !== null) {
            return $contextUser;
        }

        // Try session
        if (isset($_SESSION[self::SESSION_KEY])) {
            $sessionUserId = $_SESSION[self::SESSION_KEY];

            // Validate session user ID is a non-empty integer or string (UUID support)
            if (is_int($sessionUserId)) {
                if ($sessionUserId <= 0) {
                    unset($_SESSION[self::SESSION_KEY]);
                    return null;
                }
            } elseif (!is_string($sessionUserId) || $sessionUserId === '') {
                unset($_SESSION[self::SESSION_KEY]);
                return null;
            }

            $userFromSession = (self::$userModel)::find($sessionUserId)->unwrapOr(null);
            if ($userFromSession instanceof Model) {
                self::setContextUser($userFromSession);
                return $userFromSession;
            }
            return null;
        }

        // Try remember token
        if (isset($_COOKIE[self::REMEMBER_COOKIE])) {
            $userFromCookie = self::getUserFromRememberToken($_COOKIE[self::REMEMBER_COOKIE]);

            if ($userFromCookie instanceof Model) {
                self::setContextUser($userFromCookie);
                $_SESSION[self::SESSION_KEY] = $userFromCookie->id;
                return $userFromCookie;
            }
        }

        return null;
    }

    /**
     * Get the authenticated user's ID.
     */
    public static function id(): string|int|null
    {
        $user = self::user();
        return $user?->id;
    }

    /**
     * Clear the authentication state for the current request.
     *
     * Call this between requests in worker mode to prevent user leakage.
     */
    public static function clearRequestState(): void
    {
        self::clearContextUser();
    }

    /**
     * Regenerate the CSRF token.
     *
     * Called on login to prevent CSRF token fixation attacks.
     */
    private static function regenerateCsrfToken(): void
    {
        // Generate new CSRF token - this invalidates any pre-auth CSRF tokens
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
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
     * Get the secret key for cookie signing.
     *
     * @throws RuntimeException If APP_KEY is missing or has insufficient entropy
     */
    private static function getCookieSecret(): string
    {
        $secret = $_ENV['APP_KEY'] ?? getenv('APP_KEY');

        if ($secret === false || $secret === '') {
            throw new RuntimeException('APP_KEY environment variable must be set for cookie signing');
        }

        // Validate key has sufficient entropy
        // A proper key should be at least 32 bytes (64 hex chars or 44 base64 chars)
        $keyLength = strlen($secret);
        if ($keyLength < self::MIN_KEY_LENGTH) {
            throw new RuntimeException(
                "APP_KEY is too short ({$keyLength} chars). " .
                "Must be at least " . self::MIN_KEY_LENGTH . " characters. " .
                "Generate with: php -r \"echo bin2hex(random_bytes(32));\""
            );
        }

        // Warn about weak keys in production (common patterns)
        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
        if ($env === 'production') {
            $weakPatterns = [
                '/^(.)\1+$/',           // All same character: "aaaaaaa..."
                '/^(..)+$/',            // Repeated 2-char pattern: "abababab..."
                '/^[a-zA-Z]+$/',        // Only letters (no numbers/symbols)
                '/^[0-9]+$/',           // Only numbers
                '/^(password|secret|key|test|demo|example)/i', // Common weak prefixes
            ];

            foreach ($weakPatterns as $pattern) {
                if (preg_match($pattern, $secret)) {
                    error_log(
                        'WARNING: APP_KEY appears weak. Use cryptographically random bytes: ' .
                        'php -r "echo bin2hex(random_bytes(32));"'
                    );
                    break;
                }
            }
        }

        return $secret;
    }

    private static function setRememberToken(Model $user): void
    {
        $token = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);

        $user->remember_token = $hashedToken;
        $user->save();

        // Create cookie value with HMAC signature for integrity
        $cookieValue = $user->id . '|' . $token;
        $signature = hash_hmac('sha256', $cookieValue, self::getCookieSecret());
        $signedCookie = $signature . '.' . $cookieValue;

        setcookie(
            self::REMEMBER_COOKIE,
            $signedCookie,
            time() + self::REMEMBER_DURATION,
            '/',
            '',
            self::isHttps(),
            true
        );
    }

    private static function getUserFromRememberToken(string $cookie): ?Model
    {
        // Parse signed cookie: signature.userId|token
        $signatureParts = explode('.', $cookie, 2);

        if (count($signatureParts) !== 2) {
            return null;
        }

        [$signature, $cookieValue] = $signatureParts;

        // Verify HMAC signature
        try {
            $expectedSignature = hash_hmac('sha256', $cookieValue, self::getCookieSecret());
        } catch (RuntimeException) {
            return null;
        }

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        // Parse the verified cookie value
        $parts = explode('|', $cookieValue, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$userId, $token] = $parts;
        $hashedToken = hash('sha256', $token);

        // Reject non-numeric or non-positive user IDs before casting to prevent
        // integer overflow tricks on 32-bit environments and reject garbage cookies.
        if (!ctype_digit($userId) || $userId === '0') {
            // Still do the dummy comparison so timing is constant
            // @phpstan-ignore function.resultUnused
            $_ = hash_equals(self::DUMMY_SHA256_HASH, $hashedToken);
            return null;
        }

        $userOption = (self::$userModel)::find((int) $userId);

        if ($userOption->isNone()) {
            // Timing attack mitigation: always perform hash comparison
            // even when user doesn't exist (uses SHA256 dummy since hashedToken is SHA256)
            // @phpstan-ignore function.resultUnused (intentional for constant-time execution)
            $_ = hash_equals(self::DUMMY_SHA256_HASH, $hashedToken);
            return null;
        }

        $user = $userOption->unwrap();

        // Use DUMMY_SHA256_HASH instead of '' when token is null to ensure constant-time comparison
        // Empty string vs 64-char hash would return false immediately without constant-time behavior
        $storedToken = $user->remember_token ?? self::DUMMY_SHA256_HASH;
        if (!hash_equals($storedToken, $hashedToken)) {
            return null;
        }

        // Rotate the remember token after successful use to prevent replay attacks.
        // The old token is invalidated and a fresh one is issued.
        self::setRememberToken($user);

        return $user;
    }

    private static function isHttps(): bool
    {
        // Prefer the Request object when available — it honours trusted-proxy
        // config and checks HTTP_X_FORWARDED_PROTO for SSL-terminating load balancers.
        $ctx = RequestContext::current();
        if ($ctx !== null) {
            return $ctx->getRequest()->isSecure();
        }

        // Fallback for CLI / non-HTTP contexts (e.g. queue workers running Auth).
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    }

    private static function clearRememberToken(): void
    {
        $user = self::user();

        if ($user !== null) {
            $user->remember_token = null;
            $user->save();
        }
    }
}
