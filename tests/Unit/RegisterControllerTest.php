<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use App\Controllers\Api\Auth\RegisterController;
use App\Models\User;
use Fw\Core\Request;
use Fw\Core\RequestContext;
use Fw\Model\Model;
use Fw\Support\Str;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * H1: RegisterController::register() must return 422 (not 500) on duplicate email.
 *
 * When a user registers with an email that already exists, PDOException
 * (SQLSTATE 23000 unique constraint violation) must be caught and converted
 * to a user-friendly 422 response instead of an unhandled 500.
 */
final class RegisterControllerTest extends TestCase
{
    private RegisterController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        RequestContext::create(new Request());

        $this->createDatabase();
        $this->runMigrations();
        $this->createTokensTable();
        $this->injectDatabase();

        $this->controller = (new \ReflectionClass(RegisterController::class))->newInstanceWithoutConstructor();
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

    private function requestWith(array $body): Request
    {
        $_POST = $body;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        return new Request();
    }

    #[Test]
    public function registerReturnsDuplicateEmailErrorInsteadOf500(): void
    {
        // Seed an existing user
        $this->db->insert('users', [
            'id'         => Str::uuid(),
            'name'       => 'Existing',
            'email'      => 'taken@example.com',
            'password'   => password_hash('secret', PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $request = $this->requestWith([
            'name'                  => 'New User',
            'email'                 => 'taken@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response = $this->controller->register($request);

        $this->assertSame(
            422,
            $response->getStatusCode(),
            'Duplicate email must return 422, not 500'
        );

        $body = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('errors', $body);
        $this->assertArrayHasKey('email', $body['errors']);
    }

    #[Test]
    public function registerValidationFailsForMissingFields(): void
    {
        $request = $this->requestWith([]);

        $response = $this->controller->register($request);

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('errors', $body);
    }
}
