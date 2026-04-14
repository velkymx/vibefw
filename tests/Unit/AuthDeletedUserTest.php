<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use App\Models\User;
use Fw\Auth\Auth;
use Fw\Core\Request;
use Fw\Core\RequestContext;
use Fw\Model\Model;
use Fw\Support\Str;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * H4: When a session references a deleted user, Auth::user() must clean
 * the stale session key so subsequent requests don't hit the DB again.
 */
final class AuthDeletedUserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RequestContext::create(new Request());

        $this->createDatabase();
        $this->runMigrations();
        Model::setConnection($this->db);
        Auth::setUserModel(User::class);
    }

    #[Test]
    public function userClearsStaleSessionKeyWhenUserIsDeleted(): void
    {
        $id = Str::uuid();
        $this->db->insert('users', [
            'id'         => $id,
            'name'       => 'Ghost',
            'email'      => 'ghost@example.com',
            'password'   => password_hash('x', PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Session says user $id is logged in
        $_SESSION['_auth_user_id'] = $id;

        // Delete the user from DB (simulates account deletion)
        $this->db->delete('users', ['id' => $id]);

        // Auth::user() must return null — user no longer exists
        $user = Auth::user();
        $this->assertNull($user);

        // Critically: the stale session key must be gone so the next
        // request does NOT issue another DB query for this ghost user
        $this->assertArrayNotHasKey(
            '_auth_user_id',
            $_SESSION,
            'Auth::user() must unset stale session key for deleted user'
        );
    }

    #[Test]
    public function userReturnsNullForStaleSessionButLeavesOtherSessionData(): void
    {
        $id = Str::uuid();

        // No user in DB — simulate a stale session
        $_SESSION['_auth_user_id'] = $id;
        $_SESSION['other_key'] = 'must-survive';

        $user = Auth::user();

        $this->assertNull($user);
        $this->assertArrayNotHasKey('_auth_user_id', $_SESSION);
        $this->assertSame('must-survive', $_SESSION['other_key']);
    }
}
