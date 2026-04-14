<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Fw\Auth\ApiToken;
use Fw\Auth\NewAccessToken;
use Fw\Support\Str;
use Fw\Tests\TestCase;

final class ApiTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create database and run migrations
        $this->createDatabase();
        $this->runMigrations();
        $this->createTokensTable();

        // Inject database connection into Application
        $this->injectDatabase();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    private function createTokensTable(): void
    {
        $this->db->query("
            CREATE TABLE personal_access_tokens (
                id VARCHAR(36) PRIMARY KEY,
                user_id VARCHAR(36) NOT NULL,
                name VARCHAR(255) NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                abilities TEXT,
                expires_at DATETIME NULL,
                last_used_at DATETIME NULL,
                created_at DATETIME,
                updated_at DATETIME,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
    }

    private function injectDatabase(): void
    {
        \Fw\Model\Model::setConnection($this->db);
    }

    private function createUser(array $attributes = []): User
    {
        $defaults = [
            'id' => Str::uuid(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_ARGON2ID),
        ];

        $data = array_merge($defaults, $attributes);

        $this->db->insert('users', array_merge($data, [
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]));

        $row = $this->db->table('users')->where('email', $data['email'])->first();
        return User::find($row['id'])->unwrapOr(null);
    }

    public function testCreateReturnsNewAccessToken(): void
    {
        $user = $this->createUser();

        $result = ApiToken::create($user, 'Test Token');

        $this->assertInstanceOf(NewAccessToken::class, $result);
        $this->assertNotEmpty($result->plainTextToken);
        $this->assertInstanceOf(PersonalAccessToken::class, $result->accessToken);
    }

    public function testCreateStoresHashedToken(): void
    {
        $user = $this->createUser();

        $result = ApiToken::create($user, 'Test Token');

        // The stored token should be a hash, not the plaintext
        $storedToken = $result->accessToken->token;
        $this->assertNotEquals($result->plainTextToken, $storedToken);
        $this->assertEquals(64, strlen($storedToken)); // SHA-256 produces 64 hex chars
    }

    public function testCreateTokenFormatIncludesUserId(): void
    {
        $user = $this->createUser();

        $result = ApiToken::create($user, 'Test Token');
        $parts = explode('|', $result->plainTextToken);

        $this->assertCount(2, $parts);
        $this->assertEquals($user->id, $parts[0]);
    }

    public function testCreateWithAbilities(): void
    {
        $user = $this->createUser();
        $abilities = ['posts:read', 'posts:write'];

        $result = ApiToken::create($user, 'Test Token', $abilities);

        $this->assertEquals($abilities, $result->accessToken->abilities);
    }

    public function testCreateWithExpiration(): void
    {
        $user = $this->createUser();
        $expiresAt = new \DateTimeImmutable('+1 hour');

        $result = ApiToken::create($user, 'Test Token', ['*'], $expiresAt);

        $this->assertNotNull($result->accessToken->expires_at);
    }

    public function testFindReturnsTokenForValidPlaintext(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token');

        $found = ApiToken::find($newToken->plainTextToken);

        $this->assertInstanceOf(PersonalAccessToken::class, $found);
        $this->assertEquals($newToken->accessToken->id, $found->id);
    }

    public function testFindReturnsNullForInvalidToken(): void
    {
        $found = ApiToken::find('invalid-token');

        $this->assertNull($found);
    }

    public function testFindReturnsNullForExpiredToken(): void
    {
        $user = $this->createUser();
        $expiresAt = new \DateTimeImmutable('-1 hour');
        $newToken = ApiToken::create($user, 'Test Token', ['*'], $expiresAt);

        $found = ApiToken::find($newToken->plainTextToken);

        $this->assertNull($found);
    }

    public function testParseTokenExtractsUserIdAndRandom(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token');

        $parsed = ApiToken::parseToken($newToken->plainTextToken);

        $this->assertIsArray($parsed);
        $this->assertCount(2, $parsed);
        $this->assertEquals($user->id, $parsed[0]);
        $this->assertNotEmpty($parsed[1]);
    }

    public function testParseTokenReturnsNullForInvalidFormat(): void
    {
        $this->assertNull(ApiToken::parseToken('invalid-no-pipe'));
        $this->assertNull(ApiToken::parseToken('|random')); // Empty user ID
    }

    public function testTokensReturnsAllUserTokens(): void
    {
        $user = $this->createUser();
        ApiToken::create($user, 'Token 1');
        ApiToken::create($user, 'Token 2');
        ApiToken::create($user, 'Token 3');

        $tokens = ApiToken::tokens($user);

        $this->assertCount(3, $tokens);
    }

    public function testRevokeDeletesSpecificToken(): void
    {
        $user = $this->createUser();
        $token1 = ApiToken::create($user, 'Token 1');
        $token2 = ApiToken::create($user, 'Token 2');

        $result = ApiToken::revoke($user, $token1->accessToken->id);

        $this->assertTrue($result);
        $this->assertCount(1, ApiToken::tokens($user));
    }

    public function testRevokeReturnsFalseForNonexistentToken(): void
    {
        $user = $this->createUser();

        $result = ApiToken::revoke($user, Str::uuid());

        $this->assertFalse($result);
    }

    public function testRevokeReturnsFalseForOtherUsersToken(): void
    {
        $user1 = $this->createUser(['email' => 'user1@example.com']);
        $user2 = $this->createUser(['email' => 'user2@example.com']);
        $token = ApiToken::create($user1, 'Token');

        $result = ApiToken::revoke($user2, $token->accessToken->id);

        $this->assertFalse($result);
    }

    public function testRevokeAllDeletesAllUserTokens(): void
    {
        $user = $this->createUser();
        ApiToken::create($user, 'Token 1');
        ApiToken::create($user, 'Token 2');
        ApiToken::create($user, 'Token 3');

        $count = ApiToken::revokeAll($user);

        $this->assertEquals(3, $count);
        $this->assertCount(0, ApiToken::tokens($user));
    }

    public function testNewAccessTokenGetTokenReturnsPlaintext(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token');

        $this->assertEquals($newToken->plainTextToken, $newToken->getToken());
    }

    public function testNewAccessTokenToArrayReturnsExpectedStructure(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token', ['read']);

        $array = $newToken->toArray();

        $this->assertArrayHasKey('token', $array);
        $this->assertArrayHasKey('token_id', $array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('abilities', $array);
        $this->assertArrayHasKey('expires_at', $array);
        $this->assertEquals('Test Token', $array['name']);
        $this->assertEquals(['read'], $array['abilities']);
    }
}
