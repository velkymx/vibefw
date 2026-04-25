<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Auth;

use Fw\Auth\Auth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Item C3 — When the remember-me cookie restores a user, the session ID
 * must be regenerated before the authenticated session key is written.
 *
 * Without this, an attacker-known pre-auth session ID survives the
 * anonymous → authenticated elevation (classic session-fixation).
 *
 * `Auth::login()` already does this; the remember-me branch did not.
 *
 * Source-inspection tests (no PHP session runtime needed) match the
 * pattern of `AuthUserBootstrapsSessionTest`.
 */
final class AuthRememberMeRegeneratesSessionIdTest extends TestCase
{
    #[Test]
    public function rememberMeBranchRegeneratesSessionIdBeforeAuthSessionKeyWrite(): void
    {
        $body = $this->userMethodBody();

        $rememberBranch = $this->extractRememberMeBranch($body);

        $regenPos = strpos($rememberBranch, 'session_regenerate_id(true)');
        $writePos = strpos($rememberBranch, '$_SESSION[self::SESSION_KEY]');

        $this->assertNotFalse(
            $regenPos,
            'Auth::user() remember-me branch must call session_regenerate_id(true) before writing the auth session key.',
        );
        $this->assertNotFalse(
            $writePos,
            'Auth::user() remember-me branch must write $_SESSION[self::SESSION_KEY] (sanity).',
        );
        $this->assertLessThan(
            $writePos,
            $regenPos,
            'session_regenerate_id(true) must run before the authenticated session key is written, otherwise a pre-auth attacker-known session ID survives elevation.',
        );
    }

    #[Test]
    public function rememberMeBranchRegeneratesCsrfTokenOnElevation(): void
    {
        $rememberBranch = $this->extractRememberMeBranch($this->userMethodBody());

        $this->assertStringContainsString(
            'regenerateCsrfToken()',
            $rememberBranch,
            'Auth::user() remember-me branch must regenerate the CSRF token on elevation, mirroring login().',
        );
    }

    private function userMethodBody(): string
    {
        $method = new ReflectionMethod(Auth::class, 'user');
        $file   = file($method->getFileName());
        $start  = $method->getStartLine() - 1;
        $end    = $method->getEndLine();
        return implode('', array_slice($file, $start, $end - $start));
    }

    /**
     * Slice out the body of the `if (isset($_COOKIE[self::REMEMBER_COOKIE]))`
     * branch so the assertions above only see code from that branch — not
     * unrelated `session_regenerate_id` calls elsewhere in the method.
     */
    private function extractRememberMeBranch(string $methodBody): string
    {
        $needle = "isset(\$_COOKIE[self::REMEMBER_COOKIE])";
        $start = strpos($methodBody, $needle);
        $this->assertNotFalse($start, 'Auth::user() must check the remember-me cookie (sanity).');

        return substr($methodBody, $start);
    }
}
