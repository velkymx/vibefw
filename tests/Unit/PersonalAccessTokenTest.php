<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Fw\Support\Str;
use Fw\Tests\TestCase;

final class PersonalAccessTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createDatabase();
        $this->runMigrations();
        $this->createTokensTable();
        $this->injectDatabase();
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

    private function createUser(): string
    {
        $id = Str::uuid();
        $this->db->insert('users', [
            'id' => $id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_ARGON2ID),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    private function createToken(string $userId, array $attributes = []): PersonalAccessToken
    {
        $id = Str::uuid();
        $defaults = [
            'id' => $id,
            'user_id' => $userId,
            'name' => 'Test Token',
            'token' => hash('sha256', 'test-token-' . uniqid()),
            'abilities' => json_encode(['*']),
            'expires_at' => null,
            'last_used_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $data = array_merge($defaults, $attributes);
        $this->db->insert('personal_access_tokens', $data);

        return PersonalAccessToken::find($id)->unwrapOr(null);
    }

    public function testFindTokenReturnsTokenByHash(): void
    {
        $userId = $this->createUser();
        $hash = hash('sha256', 'unique-token');
        $token = $this->createToken($userId, ['token' => $hash]);

        $found = PersonalAccessToken::findToken($hash);

        $this->assertInstanceOf(PersonalAccessToken::class, $found);
        $this->assertEquals($token->id, $found->id);
    }

    public function testFindTokenReturnsNullForInvalidHash(): void
    {
        $found = PersonalAccessToken::findToken('nonexistent-hash');

        $this->assertNull($found);
    }

    public function testForUserReturnsAllUserTokens(): void
    {
        $userId = $this->createUser();
        $this->createToken($userId, ['name' => 'Token 1', 'token' => hash('sha256', 't1')]);
        $this->createToken($userId, ['name' => 'Token 2', 'token' => hash('sha256', 't2')]);
        $this->createToken($userId, ['name' => 'Token 3', 'token' => hash('sha256', 't3')]);

        $tokens = PersonalAccessToken::forUser($userId);

        $this->assertCount(3, $tokens);
    }

    public function testForUserReturnsEmptyCollectionForUserWithNoTokens(): void
    {
        $userId = $this->createUser();

        $tokens = PersonalAccessToken::forUser($userId);

        $this->assertInstanceOf(\Fw\Model\Collection::class, $tokens);
        $this->assertTrue($tokens->isEmpty());
    }

    public function testAbilitiesAreDecodedFromJson(): void
    {
        $userId = $this->createUser();
        $abilities = ['posts:read', 'posts:write'];
        $token = $this->createToken($userId, ['abilities' => json_encode($abilities)]);

        $this->assertEquals($abilities, $token->abilities);
    }

    public function testAbilitiesReturnsEmptyArrayForNull(): void
    {
        $userId = $this->createUser();
        $token = $this->createToken($userId, ['abilities' => null]);

        $this->assertEquals([], $token->abilities);
    }

    public function testCanReturnsTrueForExactMatch(): void
    {
        $userId = $this->createUser();
        $token = $this->createToken($userId, ['abilities' => json_encode(['posts:read', 'posts:write'])]);

        $this->assertTrue($token->can('posts:read'));
        $this->assertTrue($token->can('posts:write'));
    }

    public function testCanReturnsFalseForMissingAbility(): void
    {
        $userId = $this->createUser();
        $token = $this->createToken($userId, ['abilities' => json_encode(['posts:read'])]);

        $this->assertFalse($token->can('posts:delete'));
    }

    public function testCanReturnsTrueForWildcard(): void
    {
        $userId = $this->createUser();
        $token = $this->createToken($userId, ['abilities' => json_encode(['*'])]);

        $this->assertTrue($token->can('anything'));
        $this->assertTrue($token->can('posts:read'));
        $this->assertTrue($token->can('users:delete'));
    }

    public function testCanChecksParentAbility(): void
    {
        $userId = $this->createUser();
        $token = $this->createToken($userId, ['abilities' => json_encode(['posts'])]);

        // 'posts' parent should grant 'posts:read'
        $this->assertTrue($token->can('posts:read'));
        $this->assertTrue($token->can('posts:write'));
    }

    public function testCanChecksActionAbility(): void
    {
        $userId = $this->createUser();
        $token = $this->createToken($userId, ['abilities' => json_encode(['read'])]);

        // 'read' should grant 'posts:read' and 'users:read'
        $this->assertTrue($token->can('posts:read'));
        $this->assertTrue($token->can('users:read'));
    }

    public function testCannotReturnsOppositeOfCan(): void
    {
        $userId = $this->createUser();
        $token = $this->createToken($userId, ['abilities' => json_encode(['posts:read'])]);

        $this->assertFalse($token->cannot('posts:read'));
        $this->assertTrue($token->cannot('posts:delete'));
    }

    public function testIsExpiredReturnsFalseForNullExpiration(): void
    {
        $userId = $this->createUser();
        $token = $this->createToken($userId, ['expires_at' => null]);

        $this->assertFalse($token->isExpired());
    }

    public function testIsExpiredReturnsFalseForFutureExpiration(): void
    {
        $userId = $this->createUser();
        $futureDate = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $token = $this->createToken($userId, ['expires_at' => $futureDate]);

        $this->assertFalse($token->isExpired());
    }

    public function testIsExpiredReturnsTrueForPastExpiration(): void
    {
        $userId = $this->createUser();
        $pastDate = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $token = $this->createToken($userId, ['expires_at' => $pastDate]);

        $this->assertTrue($token->isExpired());
    }

    public function testIsValidReturnsOppositeOfIsExpired(): void
    {
        $userId = $this->createUser();
        $token = $this->createToken($userId, ['expires_at' => null]);

        $this->assertTrue($token->isValid());
    }

    public function testTouchLastUsedUpdatesTimestamp(): void
    {
        $userId = $this->createUser();
        $token = $this->createToken($userId, ['last_used_at' => null]);

        $this->assertNull($token->last_used_at);

        $token->touchLastUsed();

        $this->assertNotNull($token->last_used_at);
    }

    public function testRevokeDeletesToken(): void
    {
        $userId = $this->createUser();
        $token = $this->createToken($userId);
        $tokenId = $token->id;

        $result = $token->revoke();

        $this->assertTrue($result);
        $this->assertTrue(PersonalAccessToken::find($tokenId)->isNone());
    }

    public function testUserReturnsOwningUser(): void
    {
        $userId = $this->createUser();
        $token = $this->createToken($userId);

        $user = $token->user()->get()->unwrapOr(null);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals($userId, (string) $user->id);
    }
}
