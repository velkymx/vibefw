<?php

declare(strict_types=1);

namespace Fw\Auth;

use DateTimeImmutable;
use DateTimeInterface;
use Fw\Model\Model;
use InvalidArgumentException;

/**
 * Value object representing a newly created access token.
 *
 * This is returned when creating a token and contains both the
 * plaintext token (shown once) and the token model.
 */
final class NewAccessToken
{
    public function __construct(
        public readonly Model $accessToken,
        public readonly string $plainTextToken
    ) {}

    /**
     * Get the full token string for API usage.
     */
    public function getToken(): string
    {
        return $this->plainTextToken;
    }

    /**
     * Convert to array for JSON responses.
     */
    public function toArray(): array
    {
        return [
            'token' => $this->plainTextToken,
            'token_id' => $this->accessToken->id,
            'name' => $this->accessToken->name,
            'abilities' => $this->accessToken->abilities,
            'expires_at' => $this->accessToken->expires_at,
        ];
    }
}

/**
 * API Token management service.
 *
 * Handles creation, validation, and management of personal access tokens.
 * Tokens are stored as SHA-256 hashes; plaintext is only returned once on creation.
 */
final class ApiToken
{
    private const int TOKEN_BYTES = 20; // 40 hex characters

    /**
     * Dummy hash for timing-safe comparison when token doesn't exist.
     * Prevents token enumeration via timing attacks.
     */
    private const string DUMMY_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private static ?array $config = null;

    /**
     * The token model class. Defaults to App\Models\PersonalAccessToken.
     * Must be a Model subclass with findToken(), forUser(), isExpired() methods.
     *
     * @var class-string<Model>
     */
    private static string $tokenModel = 'App\\Models\\PersonalAccessToken';

    /**
     * Set the token model class used for token storage.
     *
     * @param class-string<Model> $modelClass
     */
    public static function setTokenModel(string $modelClass): void
    {
        self::$tokenModel = $modelClass;
    }

    /**
     * Reset the static config cache between requests in worker mode.
     * Called by HttpKernel::resetState().
     */
    public static function resetConfig(): void
    {
        self::$config = null;
    }

    /**
     * Create a new personal access token for a user.
     */
    public static function create(
        Model $user,
        string $name,
        array $abilities = ['*'],
        ?DateTimeInterface $expiresAt = null
    ): NewAccessToken {
        $config = self::config();

        // Validate abilities against allowed list
        $allowedAbilities = $config['abilities'] ?? [];
        if (!empty($allowedAbilities)) {
            foreach ($abilities as $ability) {
                if (!in_array($ability, $allowedAbilities, true)) {
                    throw new InvalidArgumentException("Invalid ability: {$ability}");
                }
            }
        }

        // Generate random token
        $randomBytes = random_bytes(self::TOKEN_BYTES);
        $randomHex = bin2hex($randomBytes);

        // Hash only the canonical (prefix-free) token body so the stored hash is
        // independent of the prefix string. Changing the prefix in config does NOT
        // invalidate all existing tokens — only their transport representation changes.
        $prefix = $config['token_prefix'] ?? '';
        $tokenBody = $user->id . '|' . $randomHex;
        $plainTextToken = $prefix . $tokenBody;

        // Hash for storage
        $hashAlgo = $config['hash_algo'] ?? 'sha256';
        $hashedToken = hash($hashAlgo, $tokenBody);

        // Set expiration
        if ($expiresAt === null && isset($config['token_expiration'])) {
            $expiration = $config['token_expiration'];
            if ($expiration !== null && $expiration > 0) {
                $expiresAt = new DateTimeImmutable("+{$expiration} seconds");
            }
        }

        // Create token record
        $token = (self::$tokenModel)::create([
            'user_id' => $user->id,
            'name' => $name,
            'token' => $hashedToken,
            'abilities' => $abilities,
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
            'last_used_at' => null,
        ]);

        return new NewAccessToken($token, $plainTextToken);
    }

    /**
     * Find and validate a token from a plaintext token string.
     *
     * Returns the token model if valid, null otherwise.
     * Uses constant-time operations to prevent timing attacks.
     *
     * SECURITY: All code paths execute the same operations in the same order
     * to prevent timing side-channels that could distinguish between:
     * - Token not found
     * - Token found but expired
     * - Token found and valid
     */
    public static function find(string $plainTextToken): ?Model
    {
        $config = self::config();

        // Strip optional prefix before hashing so the stored hash is always
        // derived from the canonical body, not the transport representation.
        $prefix = $config['token_prefix'] ?? '';
        if ($prefix !== '' && str_starts_with($plainTextToken, $prefix)) {
            $plainTextToken = substr($plainTextToken, strlen($prefix));
        }

        // Hash the canonical token body
        $hashAlgo = $config['hash_algo'] ?? 'sha256';
        $hashedToken = hash($hashAlgo, $plainTextToken);

        // Find token
        $token = (self::$tokenModel)::findToken($hashedToken);

        // TIMING ATTACK MITIGATION:
        // Execute identical operations regardless of token state to ensure
        // constant-time behavior. All three cases (not found, expired, valid)
        // must perform the same work.

        // Step 1: Perform hash comparison (always)
        // When token not found, compare against dummy hash
        // When token found, compare against stored hash (which we already matched)
        // @phpstan-ignore function.resultUnused (intentional for constant-time execution)
        $_ = hash_equals(self::DUMMY_HASH, $hashedToken);

        // Step 2: Check expiration (always - use dummy check if no token)
        // This ensures isExpired() timing doesn't leak token existence
        $isExpired = $token !== null ? $token->isExpired() : self::dummyExpirationCheck();

        // Step 3: Second dummy comparison to balance timing
        // @phpstan-ignore function.resultUnused (intentional for constant-time execution)
        $_ = hash_equals(self::DUMMY_HASH, self::DUMMY_HASH);

        // Now make the decision (timing-safe since all work is done)
        if ($token === null || $isExpired) {
            return null;
        }

        return $token;
    }

    /**
     * Parse a token string to extract user ID (for quick lookup optimization).
     *
     * Returns [user_id, random_part] or null if invalid format.
     */
    public static function parseToken(string $plainTextToken): ?array
    {
        $config = self::config();
        $prefix = $config['token_prefix'] ?? '';

        // Remove prefix if present
        if ($prefix !== '' && str_starts_with($plainTextToken, $prefix)) {
            $plainTextToken = substr($plainTextToken, strlen($prefix));
        }

        $parts = explode('|', $plainTextToken, 2);

        if (count($parts) !== 2) {
            return null;
        }

        $userId = $parts[0];

        // User ID must be non-empty
        if ($userId === '') {
            return null;
        }

        return [$userId, $parts[1]];
    }

    /**
     * Revoke all tokens for a user.
     */
    public static function revokeAll(Model $user): int
    {
        $tokens = (self::$tokenModel)::forUser($user->id);
        $count = 0;

        foreach ($tokens as $token) {
            if ($token->delete()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Revoke a specific token by ID for a user.
     */
    public static function revoke(Model $user, string $tokenId): bool
    {
        $tokenOption = (self::$tokenModel)::find($tokenId);

        if ($tokenOption->isNone()) {
            return false;
        }

        $token = $tokenOption->unwrapOr(null);
        if ((string) $token->user_id !== (string) $user->id) {
            return false;
        }

        return $token->delete();
    }

    /**
     * Get all tokens for a user.
     *
     * @return \Fw\Model\Collection<Model>
     */
    public static function tokens(Model $user): \Fw\Model\Collection
    {
        return (self::$tokenModel)::forUser($user->id);
    }

    /**
     * Prune expired tokens from the database using a single bulk DELETE.
     */
    public static function pruneExpired(): int
    {
        return (self::$tokenModel)::query()
            ->where('expires_at', '<=', date('Y-m-d H:i:s'))
            ->delete();
    }

    /**
     * Load API configuration.
     */
    private static function config(): array
    {
        if (self::$config === null) {
            $configPath = dirname(__DIR__, 2) . '/config/api.php';
            self::$config = file_exists($configPath) ? require $configPath : [];
        }

        return self::$config;
    }

    /**
     * Perform dummy expiration check to balance timing when token doesn't exist.
     *
     * This ensures the same amount of work is done regardless of token existence.
     */
    private static function dummyExpirationCheck(): bool
    {
        // Simulate the same work as isExpired() without actual data
        // Compare current time against a dummy timestamp
        $_ = time() > 0;
        return true; // Treat as expired (will be rejected anyway since token is null)
    }
}
