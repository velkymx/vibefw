<?php

declare(strict_types=1);

namespace Fw\Auth;

use Fw\Database\Connection;
use RuntimeException;

final class EmailVerification
{
    private const string TABLE = 'email_verifications';
    private const int TOKEN_EXPIRY = 86400; // 24 hours

    private static ?Connection $connection = null;

    public static function setConnection(Connection $connection): void
    {
        self::$connection = $connection;
    }

    /**
     * Reset the static connection reference between requests in worker mode.
     * Called by HttpKernel::resetState().
     */
    public static function resetConnection(): void
    {
        self::$connection = null;
    }

    public static function createToken(string $email): string
    {
        $token = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);

        $db = self::db();

        // Invalidate any existing token for this email before creating a new one.
        // Unquoted table name works across MySQL, SQLite, and PostgreSQL.
        $db->query(
            'DELETE FROM ' . self::TABLE . ' WHERE email = ?',
            [$email]
        );

        $db->insert(self::TABLE, [
            'email' => $email,
            'token' => $hashedToken,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    /**
     * Dummy hash for constant-time comparison when no token is found.
     * Prevents token enumeration via timing side-channels.
     */
    private const string DUMMY_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public static function verify(string $token): ?string
    {
        $hashedToken = hash('sha256', $token);

        $result = self::db()->selectOne(
            'SELECT * FROM ' . self::TABLE . ' WHERE token = ?',
            [$hashedToken]
        );

        // TIMING ATTACK MITIGATION: perform hash comparison regardless of
        // whether the token was found, so response timing doesn't reveal
        // whether a valid token exists in the database.
        $storedHash = $result['token'] ?? self::DUMMY_HASH;
        hash_equals($storedHash, $hashedToken);

        if ($result === null) {
            return null;
        }

        $createdAt = strtotime($result['created_at']);
        if (time() - $createdAt > self::TOKEN_EXPIRY) {
            self::deleteByHash($hashedToken);
            return null;
        }

        // Consume the token immediately — verification links are single-use.
        $email = $result['email'];
        self::deleteByHash($hashedToken);

        return $email;
    }

    public static function deleteToken(string $token): void
    {
        self::deleteByHash(hash('sha256', $token));
    }

    private static function deleteByHash(string $hashedToken): void
    {
        self::db()->query(
            'DELETE FROM ' . self::TABLE . ' WHERE token = ?',
            [$hashedToken]
        );
    }

    private static function db(): Connection
    {
        return self::$connection
            ?? throw new RuntimeException('No database connection set for EmailVerification.');
    }
}
