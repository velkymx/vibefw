<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Auth\EmailVerification;
use Fw\Database\Connection;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class EmailVerificationTest extends TestCase
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
            CREATE TABLE email_verifications (
                email      VARCHAR(255) NOT NULL,
                token      VARCHAR(64)  NOT NULL,
                created_at DATETIME     NOT NULL
            )
        ");

        EmailVerification::setConnection($this->db);
    }

    protected function tearDown(): void
    {
        EmailVerification::resetConnection();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // createToken
    // -------------------------------------------------------------------------

    #[Test]
    public function createTokenReturnsHexString(): void
    {
        $token = EmailVerification::createToken('a@a.com');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    #[Test]
    public function createTokenStoresHashedToken(): void
    {
        $token = EmailVerification::createToken('a@a.com');

        $row = $this->db->selectOne('SELECT * FROM email_verifications WHERE email = ?', ['a@a.com']);

        $this->assertNotNull($row);
        $this->assertSame(hash('sha256', $token), $row['token']);
    }

    #[Test]
    public function createTokenReplacesExistingToken(): void
    {
        EmailVerification::createToken('b@b.com');
        EmailVerification::createToken('b@b.com');

        $count = $this->db->table('email_verifications')->where('email', 'b@b.com')->count();
        $this->assertSame(1, $count);
    }

    // -------------------------------------------------------------------------
    // verify — happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function verifyReturnsNullForUnknownToken(): void
    {
        $result = EmailVerification::verify(str_repeat('0', 64));

        $this->assertNull($result);
    }

    #[Test]
    public function verifyReturnsEmailForValidToken(): void
    {
        $token = EmailVerification::createToken('c@c.com');

        $email = EmailVerification::verify($token);

        $this->assertSame('c@c.com', $email);
    }

    #[Test]
    public function verifyConsumesToken(): void
    {
        $token = EmailVerification::createToken('d@d.com');

        EmailVerification::verify($token); // first use
        $second = EmailVerification::verify($token); // must fail

        $this->assertNull($second, 'Token must be single-use');
    }

    // -------------------------------------------------------------------------
    // verify — expiry path (S-NEW-04 timing fix)
    // -------------------------------------------------------------------------

    #[Test]
    public function verifyReturnsNullForExpiredToken(): void
    {
        $token = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);

        $this->db->insert('email_verifications', [
            'email'      => 'exp@exp.com',
            'token'      => $hashedToken,
            'created_at' => date('Y-m-d H:i:s', time() - 172800), // 48 h ago
        ]);

        $result = EmailVerification::verify($token);

        $this->assertNull($result);
    }

    #[Test]
    public function verifyDoesNotEagerDeleteExpiredToken(): void
    {
        // S-NEW-04: removing the eager DELETE from the expiry path prevents the
        // extra DB write that distinguished expired from non-existent tokens.
        $token = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);

        $this->db->insert('email_verifications', [
            'email'      => 'old@old.com',
            'token'      => $hashedToken,
            'created_at' => date('Y-m-d H:i:s', time() - 172800),
        ]);

        EmailVerification::verify($token); // should NOT delete

        $remaining = $this->db->table('email_verifications')->where('email', 'old@old.com')->count();
        $this->assertSame(1, $remaining, 'Expired row must survive verify(); deleteExpired() handles cleanup');
    }

    // -------------------------------------------------------------------------
    // deleteToken
    // -------------------------------------------------------------------------

    #[Test]
    public function deleteTokenRemovesRow(): void
    {
        $token = EmailVerification::createToken('del@del.com');

        EmailVerification::deleteToken($token);

        $row = $this->db->selectOne('SELECT * FROM email_verifications WHERE email = ?', ['del@del.com']);
        $this->assertNull($row);
    }
}
