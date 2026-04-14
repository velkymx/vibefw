<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use App\Models\User;
use Fw\Auth\Auth;
use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\RequestContext;
use Fw\Core\Response;
use Fw\Model\Model;
use Fw\Support\Option;
use Fw\Support\Str;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Concrete subclass exposing protected Controller methods for testing.
 * Named class (not anonymous) so newInstanceWithoutConstructor() works.
 */
final class TestableController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->json([]);
    }

    public function exposeUser(): Option
    {
        return $this->user();
    }

    public function exposeIsAuthenticated(): bool
    {
        return $this->isAuthenticated();
    }
}

/**
 * Tests for Controller::user() and Controller::isAuthenticated().
 *
 * user() / isAuthenticated() never touch $this->app, so we skip the
 * Application constructor via newInstanceWithoutConstructor().
 */
final class ControllerUserTest extends TestCase
{
    private TestableController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $rc = new ReflectionClass(TestableController::class);
        /** @var TestableController $ctrl */
        $ctrl = $rc->newInstanceWithoutConstructor();
        $this->controller = $ctrl;
    }

    // ── Negative (no DB needed) ──────────────────────────────────────────

    #[Test]
    public function userReturnsNoneWhenNoSessionExists(): void
    {
        $_SESSION = [];
        RequestContext::clear();

        $this->assertTrue($this->controller->exposeUser()->isNone());
    }

    #[Test]
    public function userReturnsNoneWhenOnlyLegacySessionKeySet(): void
    {
        // Buggy code reads $_SESSION['user']. Correct code must ignore it.
        $_SESSION['user'] = 999;
        RequestContext::clear();

        $this->assertTrue(
            $this->controller->exposeUser()->isNone(),
            'user() must NOT read $_SESSION["user"] — that key is never set by Auth'
        );
    }

    #[Test]
    public function isAuthenticatedReturnsFalseWithEmptySession(): void
    {
        $_SESSION = [];
        RequestContext::clear();

        $this->assertFalse($this->controller->exposeIsAuthenticated());
    }

    // ── Positive (requires DB + Auth session key) ────────────────────────

    #[Test]
    public function userReturnsSomeForAuthenticatedSession(): void
    {
        $this->createDatabase();
        $this->runMigrations();
        Model::setConnection($this->db);

        Auth::setUserModel(User::class);

        $id = Str::uuid();
        $this->db->insert('users', [
            'id'         => $id,
            'name'       => 'Alice',
            'email'      => 'alice@example.com',
            'password'   => password_hash('secret', PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $_SESSION['_auth_user_id'] = $id;
        RequestContext::create(new Request());

        $result = $this->controller->exposeUser();

        $this->assertTrue($result->isSome(), 'user() must return Some for valid session');
        $result->match(
            some: fn($u) => $this->assertSame($id, (string) $u->id),
            none: fn() => $this->fail('Expected Some'),
        );
    }

    #[Test]
    public function isAuthenticatedReturnsTrueForAuthenticatedSession(): void
    {
        $this->createDatabase();
        $this->runMigrations();
        Model::setConnection($this->db);

        Auth::setUserModel(User::class);

        $id = Str::uuid();
        $this->db->insert('users', [
            'id'         => $id,
            'name'       => 'Bob',
            'email'      => 'bob@example.com',
            'password'   => password_hash('secret', PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $_SESSION['_auth_user_id'] = $id;
        RequestContext::create(new Request());

        $this->assertTrue($this->controller->exposeIsAuthenticated());
    }
}
