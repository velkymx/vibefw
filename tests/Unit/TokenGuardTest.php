<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Fw\Auth\ApiToken;
use Fw\Auth\Contracts\RevocableTokenInterface;
use Fw\Auth\TokenGuard;
use Fw\Core\Request;
use Fw\Support\Str;
use Fw\Tests\TestCase;

final class TokenGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize RequestContext for tests that use it (via TokenGuard)
        \Fw\Core\RequestContext::create(new \Fw\Core\Request());

        // Reset TokenGuard state
        TokenGuard::logout();

        // Create database and run migrations
        $this->createDatabase();
        $this->runMigrations();
        $this->createTokensTable();
        $this->injectDatabase();
    }

    protected function tearDown(): void
    {
        TokenGuard::logout();
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

        $row = $this->db->table('users')->where('email', $data['email'])->first()->unwrapOrElse(fn () => throw new \RuntimeException('User not found'));
        return User::find($row['id'])->unwrapOr(null);
    }

    public function testAuthenticateReturnsUserForValidToken(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token');

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $newToken->plainTextToken;
        $request = new Request();

        $result = TokenGuard::authenticate($request);

        $this->assertInstanceOf(User::class, $result);
        $this->assertEquals($user->id, $result->id);
    }

    public function testAuthenticateReturnsNullForMissingToken(): void
    {
        $request = new Request();

        $result = TokenGuard::authenticate($request);

        $this->assertNull($result);
    }

    public function testAuthenticateReturnsNullForInvalidToken(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid-token';
        $request = new Request();

        $result = TokenGuard::authenticate($request);

        $this->assertNull($result);
    }

    public function testAuthenticateTokenSetsUser(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token');

        TokenGuard::authenticateToken($newToken->plainTextToken);

        $this->assertInstanceOf(User::class, TokenGuard::user());
        $this->assertEquals($user->id, TokenGuard::user()->id);
    }

    public function testCheckReturnsTrueWhenAuthenticated(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token');

        TokenGuard::authenticateToken($newToken->plainTextToken);

        $this->assertTrue(TokenGuard::check());
    }

    public function testCheckReturnsFalseWhenNotAuthenticated(): void
    {
        $this->assertFalse(TokenGuard::check());
    }

    public function testUserReturnsNullWhenNotAuthenticated(): void
    {
        $this->assertNull(TokenGuard::user());
    }

    public function testCurrentTokenReturnsTokenWhenAuthenticated(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token');

        TokenGuard::authenticateToken($newToken->plainTextToken);

        $this->assertInstanceOf(PersonalAccessToken::class, TokenGuard::currentToken());
        $this->assertEquals($newToken->accessToken->id, TokenGuard::currentToken()->id);
    }

    public function testCurrentTokenReturnsNullWhenNotAuthenticated(): void
    {
        $this->assertNull(TokenGuard::currentToken());
    }

    public function testTokenCanReturnsTrueForGrantedAbility(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token', ['posts:read', 'posts:write']);

        TokenGuard::authenticateToken($newToken->plainTextToken);

        $this->assertTrue(TokenGuard::tokenCan('posts:read'));
        $this->assertTrue(TokenGuard::tokenCan('posts:write'));
    }

    public function testTokenCanReturnsFalseForDeniedAbility(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token', ['posts:read']);

        TokenGuard::authenticateToken($newToken->plainTextToken);

        $this->assertFalse(TokenGuard::tokenCan('posts:delete'));
    }

    public function testTokenCanReturnsTrueForWildcard(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token', ['*']);

        TokenGuard::authenticateToken($newToken->plainTextToken);

        $this->assertTrue(TokenGuard::tokenCan('anything'));
        $this->assertTrue(TokenGuard::tokenCan('posts:read'));
        $this->assertTrue(TokenGuard::tokenCan('users:delete'));
    }

    public function testTokenCanReturnsFalseWhenNotAuthenticated(): void
    {
        $this->assertFalse(TokenGuard::tokenCan('posts:read'));
    }

    public function testTokenCannotReturnsOppositeOfTokenCan(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token', ['posts:read']);

        TokenGuard::authenticateToken($newToken->plainTextToken);

        $this->assertFalse(TokenGuard::tokenCannot('posts:read'));
        $this->assertTrue(TokenGuard::tokenCannot('posts:delete'));
    }

    public function testTokenAbilitiesReturnsAbilitiesArray(): void
    {
        $user = $this->createUser();
        $abilities = ['posts:read', 'posts:write'];
        $newToken = ApiToken::create($user, 'Test Token', $abilities);

        TokenGuard::authenticateToken($newToken->plainTextToken);

        $this->assertEquals($abilities, TokenGuard::tokenAbilities());
    }

    public function testTokenAbilitiesReturnsEmptyArrayWhenNotAuthenticated(): void
    {
        $this->assertEquals([], TokenGuard::tokenAbilities());
    }

    public function testIdReturnsUserIdWhenAuthenticated(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token');

        TokenGuard::authenticateToken($newToken->plainTextToken);

        $this->assertEquals($user->id, TokenGuard::id());
    }

    public function testIdReturnsNullWhenNotAuthenticated(): void
    {
        $this->assertNull(TokenGuard::id());
    }

    public function testLogoutClearsState(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token');

        TokenGuard::authenticateToken($newToken->plainTextToken);
        $this->assertTrue(TokenGuard::check());

        TokenGuard::logout();

        $this->assertFalse(TokenGuard::check());
        $this->assertNull(TokenGuard::user());
        $this->assertNull(TokenGuard::currentToken());
    }

    public function testSetUserManuallyAuthenticates(): void
    {
        $user = $this->createUser();

        TokenGuard::setUser($user);

        $this->assertTrue(TokenGuard::check());
        $this->assertEquals($user->id, TokenGuard::user()->id);
    }

    public function testSetUserWithTokenSetsCurrentToken(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token', ['posts:read']);

        TokenGuard::setUser($user, $newToken->accessToken);

        $this->assertTrue(TokenGuard::tokenCan('posts:read'));
    }

    public function testAuthenticateUpdatesLastUsedAt(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token');

        $this->assertNull($newToken->accessToken->last_used_at);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $newToken->plainTextToken;
        $request = new Request();
        TokenGuard::authenticate($request);

        // Refresh token from database
        $token = PersonalAccessToken::find($newToken->accessToken->id)->unwrapOr(null);
        $this->assertNotNull($token->last_used_at);
    }

    public function testCurrentTokenImplementsRevocableTokenInterface(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token');
        TokenGuard::setUser($user, $newToken->accessToken);

        $token = TokenGuard::currentToken();

        $this->assertInstanceOf(RevocableTokenInterface::class, $token);
    }

    public function testCurrentTokenRevokeDeletesRecord(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token');
        TokenGuard::setUser($user, $newToken->accessToken);

        /** @var RevocableTokenInterface $token */
        $token = TokenGuard::currentToken();
        $this->assertNotNull($token);
        $token->revoke();

        $found = PersonalAccessToken::find($newToken->accessToken->id)->unwrapOr(null);
        $this->assertNull($found);
    }

    public function testPersonalAccessTokenImplementsRevocableTokenInterface(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'Test Token');

        $this->assertInstanceOf(RevocableTokenInterface::class, $newToken->accessToken);
    }
}
