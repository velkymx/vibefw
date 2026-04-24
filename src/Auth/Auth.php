<?php

declare(strict_types=1);

namespace Fw\Auth;

use Fw\Core\RequestContext;
use Fw\Model\Model;
use Fw\Support\Option;
use LogicException;
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
     * Cached dummy hash for timing-safe comparison when user doesn't exist.
     * Initialized lazily with PASSWORD_DEFAULT so it always matches the
     * algorithm used for real password hashes, regardless of PHP version.
     */
    private static ?string $dummyHash = null;

    /**
     * The user model class. Must have findByEmail() and verifyPassword() methods.
     *
     * @var class-string<Model>
     */
    private static string $userModel = 'App\\Models\\User';

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
            $_ = password_verify($password, self::getDummyHash());
            return false;
        }

        $user = $userOption->unwrapOr(null);

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
        if (!self::ensureSessionStarted()) {
            return;
        }
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

        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::SESSION_KEY]);
            session_regenerate_id(true);
        }
    }

    /**
     * Check if a user is authenticated.
     */
    public static function check(): bool
    {
        return self::user()->isSome();
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
     * Returns Option::none() when not authenticated, Option::some($user) when authenticated.
     * Uses RequestContext for request-scoped storage to prevent
     * user state from leaking between concurrent requests.
     *
     * @return Option<Model>
     */
    public static function user(): Option
    {
        // Check RequestContext first (request-scoped cache)
        $contextUser = self::getContextUser();
        if ($contextUser !== null) {
            return Option::some($contextUser);
        }

        $sessionReady = self::ensureSessionStarted();

        // Try session
        if ($sessionReady && isset($_SESSION[self::SESSION_KEY])) {
            $sessionUserId = $_SESSION[self::SESSION_KEY];

            // Validate session user ID is a non-empty integer or string (UUID support)
            if (is_int($sessionUserId)) {
                if ($sessionUserId <= 0) {
                    unset($_SESSION[self::SESSION_KEY]);
                    return Option::none();
                }
            } elseif (!is_string($sessionUserId) || $sessionUserId === '') {
                unset($_SESSION[self::SESSION_KEY]);
                return Option::none();
            }

            $userFromSession = (self::$userModel)::find($sessionUserId)->unwrapOr(null);
            if ($userFromSession instanceof Model) {
                self::setContextUser($userFromSession);
                return Option::some($userFromSession);
            }

            // User no longer exists in DB (e.g. account deleted while session was active).
            // Remove the stale key so subsequent requests don't issue another DB query.
            unset($_SESSION[self::SESSION_KEY]);
            return Option::none();
        }

        // Try remember token
        if (isset($_COOKIE[self::REMEMBER_COOKIE])) {
            $userFromCookie = $_COOKIE[self::REMEMBER_COOKIE]
                |> (static fn (string $token): ?Model => self::getUserFromRememberToken($token));

            if ($userFromCookie instanceof Model) {
                self::setContextUser($userFromCookie);
                if ($sessionReady) {
                    $_SESSION[self::SESSION_KEY] = $userFromCookie->id;
                }
                return Option::some($userFromCookie);
            }
        }

        return Option::none();
    }

    /**
     * Get the authenticated user's ID.
     */
    public static function id(): string|int|null
    {
        return self::user()->unwrapOr(null)?->id;
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
     * Validate APP_KEY at bootstrap. Call once from app startup so misconfigured
     * keys fail loudly before the first request hits the remember-me path.
     *
     * @throws RuntimeException If APP_KEY is missing, too short, or weak in production.
     */
    public static function validateAppKey(): void
    {
        self::resolveAppKey();
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
     * Ensure a PHP session is active before session-sensitive work.
     *
     * Returns false (without warning) if the session is disabled or if
     * headers have already been sent, in which case the blocking frame
     * is logged. This keeps worker-mode callers from emitting spurious
     * warnings and makes session-fixation protection a no-op rather
     * than a fatal when output has already been flushed.
     */
    private static function ensureSessionStarted(): bool
    {
        $status = session_status();
        if ($status === PHP_SESSION_ACTIVE) {
            return true;
        }
        if ($status !== PHP_SESSION_NONE) {
            return false;
        }
        if (headers_sent($file, $line)) {
            error_log(sprintf(
                'Cannot start session for Auth: headers already sent in %s on line %d',
                $file,
                $line,
            ));
            return false;
        }

        return session_start();
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
        return self::resolveAppKey();
    }

    /**
     * Resolve and validate APP_KEY. Shared by bootstrap validation and runtime
     * cookie signing so both paths enforce identical guarantees.
     */
    private static function resolveAppKey(): string
    {
        $secret = $_ENV['APP_KEY'] ?? getenv('APP_KEY');

        if ($secret === false || $secret === '') {
            throw new RuntimeException('APP_KEY environment variable must be set for cookie signing');
        }

        $keyLength = strlen($secret);
        if ($keyLength < self::MIN_KEY_LENGTH) {
            throw new RuntimeException(
                "APP_KEY is too short ({$keyLength} chars). " .
                "Must be at least " . self::MIN_KEY_LENGTH . " characters. " .
                "Generate with: php -r \"echo bin2hex(random_bytes(32));\""
            );
        }

        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
        if ($env === 'production') {
            $weakPatterns = [
                '/^(.)\1+$/',
                '/^(..)\1+$/',
                '/^[a-zA-Z]+$/',
                '/^[0-9]+$/',
                '/^(password|secret|key|test|demo|example)/i',
            ];

            foreach ($weakPatterns as $pattern) {
                if (preg_match($pattern, $secret)) {
                    throw new RuntimeException(
                        'APP_KEY is cryptographically weak. ' .
                        'Generate a secure key with: php -r "echo bin2hex(random_bytes(32));"'
                    );
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
        $user->save()->match(ok: fn () => null, err: fn ($e) => throw $e);

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

        $user = $userOption->unwrapOrElse(fn () => throw new LogicException('unreachable: isNone guard passed'));

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
        // Always defer to Request::isSecure(), which honours
        // Request::setTrustedProxies() when evaluating X-Forwarded-Proto.
        // Without a RequestContext there is no trustworthy signal — reading
        // $_SERVER['HTTPS'] / SERVER_PORT directly would let a misconfigured
        // proxy (or a CLI worker running behind one) flip the cookie secure
        // flag based on unvalidated input.
        return RequestContext::current()?->getRequest()->isSecure() ?? false;
    }

    /**
     * Get or lazily create the dummy hash for timing-safe comparisons.
     *
     * Uses PASSWORD_DEFAULT so the hash algorithm always matches whatever
     * algorithm the application uses for real passwords, preventing timing
     * side-channels when the default changes (e.g. bcrypt → Argon2id).
     */
    private static function getDummyHash(): string
    {
        return self::$dummyHash ??= password_hash('dummy', PASSWORD_DEFAULT);
    }

    private static function clearRememberToken(): void
    {
        self::user()->match(
            some: function (Model $user): void {
                $user->remember_token = null;
                $user->save()->match(ok: fn () => null, err: fn ($e) => throw $e);
            },
            none: fn () => null,
        );
    }
}
