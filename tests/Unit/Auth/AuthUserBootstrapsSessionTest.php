<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Auth;

use Fw\Auth\Auth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Locks in the contract that Auth::user() bootstraps the session before
 * touching $_SESSION. Without this, real (non-test) requests read a
 * never-started session and never see the logged-in user.
 */
final class AuthUserBootstrapsSessionTest extends TestCase
{
    #[Test]
    public function userMethodCallsEnsureSessionStartedBeforeReadingSession(): void
    {
        $method = new ReflectionMethod(Auth::class, 'user');
        $file   = file($method->getFileName());
        $start  = $method->getStartLine() - 1;
        $end    = $method->getEndLine();
        $body   = implode('', array_slice($file, $start, $end - $start));

        $ensurePos = strpos($body, 'self::ensureSessionStarted()');
        $sessionReadPos = strpos($body, '$_SESSION[self::SESSION_KEY]');

        $this->assertNotFalse(
            $ensurePos,
            'Auth::user() must call self::ensureSessionStarted() so $_SESSION reads see a started session.',
        );
        $this->assertNotFalse(
            $sessionReadPos,
            'Auth::user() must read $_SESSION[self::SESSION_KEY] (sanity).',
        );
        $this->assertLessThan(
            $sessionReadPos,
            $ensurePos,
            'ensureSessionStarted() must run before the first $_SESSION read, otherwise the read is against a never-started session.',
        );
    }
}
