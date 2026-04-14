<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use App\Controllers\Api\Auth\LoginController;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Fw\Auth\ApiToken;
use Fw\Auth\TokenGuard;
use Fw\Core\Request;
use Fw\Core\RequestContext;
use Fw\Model\Model;
use Fw\Support\Str;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * C3: API logout must revoke the Bearer token, not just clear the session.
 *
 * Without token revocation, an attacker with an intercepted token can
 * continue calling protected API endpoints after the user logs out.
 */
final class ApiLogoutTest extends TestCase
{
    private LoginController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        RequestContext::create(new Request());
        TokenGuard::logout();

        $this->createDatabase();
        $this->runMigrations();
        $this->createTokensTable();
        $this->injectDatabase();

        $this->controller = (new \ReflectionClass(LoginController::class))->newInstanceWithoutConstructor();
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
        Model::setConnection($this->db);
    }

    private function createUser(): User
    {
        $id = Str::uuid();
        $this->db->insert('users', [
            'id'         => $id,
            'name'       => 'Alice',
            'email'      => 'alice@example.com',
            'password'   => password_hash('secret', PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return User::find($id)->unwrapOr(null);
    }

    #[Test]
    public function logoutRevokesCurrentBearerToken(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'spa-login');

        // Simulate ApiAuthMiddleware: authenticate the token → stored in RequestContext
        TokenGuard::authenticateToken($newToken->plainTextToken);

        // Token must be in DB before logout
        $tokenExists = PersonalAccessToken::where('user_id', (string) $user->id)->count();
        $this->assertSame(1, $tokenExists, 'Token must exist before logout');

        // Act
        $this->controller->logout(new Request());

        // Token must be deleted after logout
        $tokenAfter = PersonalAccessToken::where('user_id', (string) $user->id)->count();
        $this->assertSame(
            0,
            $tokenAfter,
            'logout() must revoke the current Bearer token — token still in DB (C3 bug)'
        );
    }

    #[Test]
    public function logoutWithoutTokenStillSucceeds(): void
    {
        // No token authenticated — session-only logout path must not crash
        $response = $this->controller->logout(new Request());

        $data = json_decode($response->getBody(), true);
        $this->assertSame('Logged out successfully', $data['message']);
    }

    #[Test]
    public function logoutReturns200WithSuccessMessage(): void
    {
        $user = $this->createUser();
        $newToken = ApiToken::create($user, 'spa-login');
        TokenGuard::authenticateToken($newToken->plainTextToken);

        $response = $this->controller->logout(new Request());

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertSame('Logged out successfully', $data['message']);
    }
}
