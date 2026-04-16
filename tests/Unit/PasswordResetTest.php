<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Auth\PasswordReset;
use Fw\Database\Connection;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PasswordResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Connection::reset();
        $this->db = Connection::getInstance([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->db->query("
            CREATE TABLE password_resets (
                email      VARCHAR(255) NOT NULL,
                token      VARCHAR(64)  NOT NULL,
                created_at DATETIME     NOT NULL
            )
        ");

        PasswordReset::setConnection($this->db);
    }

    protected function tearDown(): void
    {
        PasswordReset::resetConnection();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // createToken
    // -------------------------------------------------------------------------

    #[Test]
    public function createTokenReturnsHexString(): void
    {
        $token = PasswordReset::createToken('a@a.com');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    #[Test]
    public function createTokenStoresHashedToken(): void
    {
        $token = PasswordReset::createToken('a@a.com');

        $row = $this->db->selectOne('SELECT * FROM password_resets WHERE email = ?', ['a@a.com']);

        $this->assertNotNull($row);
        $this->assertSame(hash('sha256', $token), $row['token']);
    }

    #[Test]
    public function createTokenReplacesExistingToken(): void
    {
        PasswordReset::createToken('b@b.com');
        PasswordReset::createToken('b@b.com');

        $count = $this->db->table('password_resets')->where('email', 'b@b.com')->count();
        $this->assertSame(1, $count);
    }

    // -------------------------------------------------------------------------
    // findByToken — happy paths
    // -------------------------------------------------------------------------

    #[Test]
    public function findByTokenReturnsNullForUnknownToken(): void
    {
        $result = PasswordReset::findByToken(str_repeat('0', 64));

        $this->assertNull($result);
    }

    #[Test]
    public function findByTokenReturnsRowForValidToken(): void
    {
        $token = PasswordReset::createToken('c@c.com');

        $result = PasswordReset::findByToken($token);

        $this->assertNotNull($result);
        $this->assertSame('c@c.com', $result['email']);
    }

    // -------------------------------------------------------------------------
    // findByToken — expiry path (S-NEW-01 timing fix)
    // -------------------------------------------------------------------------

    #[Test]
    public function findByTokenReturnsNullForExpiredToken(): void
    {
        $token = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);

        // Insert already-expired row (2 hours ago)
        $this->db->insert('password_resets', [
            'email'      => 'd@d.com',
            'token'      => $hashedToken,
            'created_at' => date('Y-m-d H:i:s', time() - 7200),
        ]);

        $result = PasswordReset::findByToken($token);

        $this->assertNull($result);
    }

    #[Test]
    public function findByTokenDoesNotEagerDeleteExpiredToken(): void
    {
        // S-NEW-01: removing the eager DELETE from findByToken prevents the extra
        // DB write that distinguished expired tokens from non-existent ones.
        $token = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);

        $this->db->insert('password_resets', [
            'email'      => 'e@e.com',
            'token'      => $hashedToken,
            'created_at' => date('Y-m-d H:i:s', time() - 7200),
        ]);

        PasswordReset::findByToken($token); // should NOT delete the row

        $remaining = $this->db->table('password_resets')->where('email', 'e@e.com')->count();
        $this->assertSame(1, $remaining, 'Expired row must survive findByToken; deleteExpired() handles cleanup');
    }

    // -------------------------------------------------------------------------
    // deleteToken
    // -------------------------------------------------------------------------

    #[Test]
    public function deleteTokenRemovesRow(): void
    {
        $token = PasswordReset::createToken('f@f.com');

        PasswordReset::deleteToken($token);

        $row = $this->db->selectOne('SELECT * FROM password_resets WHERE email = ?', ['f@f.com']);
        $this->assertNull($row);
    }

    // -------------------------------------------------------------------------
    // deleteExpired
    // -------------------------------------------------------------------------

    #[Test]
    public function deleteExpiredRemovesStaleRows(): void
    {
        // Fresh row (should survive)
        PasswordReset::createToken('fresh@fw.com');

        // Stale row (2 hours ago)
        $this->db->insert('password_resets', [
            'email'      => 'stale@fw.com',
            'token'      => hash('sha256', 'staletoken'),
            'created_at' => date('Y-m-d H:i:s', time() - 7200),
        ]);

        $deleted = PasswordReset::deleteExpired();

        $this->assertSame(1, $deleted);
        $this->assertNull(
            $this->db->selectOne('SELECT * FROM password_resets WHERE email = ?', ['stale@fw.com'])
        );
        $this->assertNotNull(
            $this->db->selectOne('SELECT * FROM password_resets WHERE email = ?', ['fresh@fw.com'])
        );
    }
}
