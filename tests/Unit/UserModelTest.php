<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use App\Models\User;
use Fw\Database\Connection;
use Fw\Model\Model;
use Fw\Support\Str;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * M9: markEmailAsVerified() must persist. M10: remember_token must be hidden.
 */
final class UserModelTest extends TestCase
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
            CREATE TABLE users (
                id VARCHAR(36) PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                email_verified_at DATETIME,
                remember_token VARCHAR(255),
                created_at DATETIME,
                updated_at DATETIME
            )
        ");

        Model::setConnection($this->db);
    }

    private function insertUser(array $extra = []): string
    {
        $id = Str::uuid();
        $this->db->insert('users', array_merge([
            'id'         => $id,
            'name'       => 'Test',
            'email'      => 'test@example.com',
            'password'   => password_hash('x', PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $extra));
        return $id;
    }

    // M9 ─────────────────────────────────────────────────────────────────

    #[Test]
    public function markEmailAsVerifiedPersistsTimestamp(): void
    {
        $id = $this->insertUser();
        $user = User::find($id)->unwrapOr(null);
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);

        $user->markEmailAsVerified();

        $row = $this->db->table('users')->where('id', '=', $id)->first()->unwrapOrElse(fn () => throw new \RuntimeException('User not found'));
        $this->assertNotNull($row['email_verified_at'], 'email_verified_at must be persisted to DB');
    }

    // M10 ────────────────────────────────────────────────────────────────

    #[Test]
    public function rememberTokenExcludedFromJsonSerialization(): void
    {
        $id = $this->insertUser(['remember_token' => 'secret-token-hash']);
        $user = User::find($id)->unwrapOr(null);
        $this->assertNotNull($user);

        $json = json_encode($user);
        $data = json_decode($json, true);

        $this->assertArrayNotHasKey(
            'remember_token',
            $data,
            'remember_token must not appear in JSON output'
        );
    }

    #[Test]
    public function passwordExcludedFromJsonSerialization(): void
    {
        $id = $this->insertUser();
        $user = User::find($id)->unwrapOr(null);

        $data = json_decode(json_encode($user), true);
        $this->assertArrayNotHasKey('password', $data);
    }
}
