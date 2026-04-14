<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use App\Controllers\Api\Auth\LoginController;
use Fw\Core\Application;
use Fw\Core\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SpaLoginSessionTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure no session is active before each test
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    #[Test]
    public function loginControllerDoesNotUseAuthAttempt(): void
    {
        // Verify that LoginController::login() no longer calls Auth::attempt()
        // by inspecting the source code. Auth::attempt() starts a session.
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/app/Controllers/Api/Auth/LoginController.php'
        );

        $this->assertStringNotContainsString(
            'Auth::attempt(',
            $source,
            'LoginController::login() must not call Auth::attempt() — it starts a session, incompatible with token-only SPA auth'
        );
    }

    #[Test]
    public function logoutControllerDoesNotCallAuthLogout(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/app/Controllers/Api/Auth/LoginController.php'
        );

        $this->assertStringNotContainsString(
            'Auth::logout(',
            $source,
            'LoginController::logout() must not call Auth::logout() — it clears session, incompatible with token-only SPA auth'
        );
    }
}
