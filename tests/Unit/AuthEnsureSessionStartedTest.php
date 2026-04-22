<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Auth\Auth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Locks in the session-bootstrap contract used by Auth::login().
 *
 * The contract is:
 *   1. An already-active session must be accepted as-is.
 *   2. If headers have already been sent, the helper must refuse to
 *      start a session and log the blocking frame rather than warning.
 *   3. login() must call ensureSessionStarted() *before*
 *      session_regenerate_id() so fixation protection is not
 *      attempted against a non-existent session.
 */
final class AuthEnsureSessionStartedTest extends TestCase
{
    #[Test]
    public function ensureSessionStartedReturnsTrueWhenSessionActive(): void
    {
        $this->assertSame(PHP_SESSION_ACTIVE, session_status(), 'bootstrap should leave the CLI session active');

        $method = new ReflectionMethod(Auth::class, 'ensureSessionStarted');
        $this->assertTrue($method->invoke(null));
    }

    #[Test]
    public function loginCallsEnsureSessionStartedBeforeRegenerateId(): void
    {
        $source = file_get_contents(BASE_PATH . '/src/Auth/Auth.php');
        $this->assertIsString($source);

        $ensurePos = strpos($source, 'self::ensureSessionStarted()');
        $regenPos = strpos($source, 'session_regenerate_id(true)');

        $this->assertNotFalse($ensurePos, 'Auth::login() must call ensureSessionStarted().');
        $this->assertNotFalse($regenPos, 'Auth::login() must still regenerate the session ID.');
        $this->assertLessThan(
            $regenPos,
            $ensurePos,
            'ensureSessionStarted() must run before session_regenerate_id() so regeneration never fires against a dead session.',
        );
    }

    #[Test]
    public function ensureSessionStartedGuardsAgainstHeadersSent(): void
    {
        // Structural: the helper must consult headers_sent() so callers in
        // worker mode don't emit warnings when output has already been flushed.
        $method = new ReflectionMethod(Auth::class, 'ensureSessionStarted');

        $file = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($file, $start, $end - $start));

        $this->assertStringContainsString(
            'headers_sent(',
            $body,
            'ensureSessionStarted() must check headers_sent() before calling session_start().',
        );
    }
}
