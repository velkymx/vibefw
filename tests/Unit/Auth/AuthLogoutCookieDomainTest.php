<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Auth;

use Fw\Auth\Auth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Item H9 — Auth::logout() must delete the remember cookie using the same
 * domain/path/secure/samesite attributes that were used when setting it.
 *
 * If the cookie was set with an explicit Domain (e.g. .example.com for
 * multi-subdomain apps), deleting with domain='' creates a host-scoped
 * cookie instead of removing the domain-scoped one — the original cookie
 * survives and re-authenticates the user on the next request.
 *
 * Source-inspection tests verify that both setRememberToken() and logout()
 * share the same cookie-parameter resolution logic.
 */
final class AuthLogoutCookieDomainTest extends TestCase
{
    #[Test]
    public function logoutAndSetRememberTokenUseSameCookieParameterHelper(): void
    {
        $logoutBody = $this->methodBody('logout');
        $setBody = $this->methodBody('setRememberToken');

        $this->assertStringContainsString(
            'cookieParams(',
            $logoutBody,
            'Auth::logout() must use a shared cookieParams() helper to resolve domain/path/secure/samesite for setcookie(), not hardcode them.',
        );
        $this->assertStringContainsString(
            'cookieParams(',
            $setBody,
            'Auth::setRememberToken() must use a shared cookieParams() helper to resolve domain/path/secure/samesite for setcookie(), not hardcode them.',
        );
    }

    #[Test]
    public function logoutDoesNotHardcodeEmptyDomain(): void
    {
        $logoutBody = $this->methodBody('logout');

        $this->assertStringNotContainsString(
            "setcookie(self::REMEMBER_COOKIE, '', time() - 3600, '/', '',",
            $logoutBody,
            'Auth::logout() must not hardcode domain=\'\' when deleting the remember cookie — it must match the domain used at set-time.',
        );
    }

    #[Test]
    public function setRememberTokenDoesNotHardcodeEmptyDomain(): void
    {
        $setBody = $this->methodBody('setRememberToken');

        $this->assertStringNotContainsString(
            "'/',\n            '',",
            $setBody,
            'Auth::setRememberToken() must not hardcode domain=\'\' — it must use the configured cookie domain so logout can match it.',
        );
    }

    #[Test]
    public function cookieParamsHelperReadsSessionDomainFromConfig(): void
    {
        $helperBody = $this->methodBody('cookieParams');

        $this->assertStringContainsString(
            'SESSION_DOMAIN',
            $helperBody,
            'cookieParams() must read SESSION_DOMAIN from environment so deployments can set .example.com for multi-subdomain apps.',
        );
    }

    #[Test]
    public function cookieParamsHelperReadsSecureAndSameSiteFromConfig(): void
    {
        $helperBody = $this->methodBody('cookieParams');

        $this->assertStringContainsString(
            'SESSION_SAME_SITE',
            $helperBody,
            'cookieParams() must read SESSION_SAME_SITE from environment.',
        );
    }

    private function methodBody(string $method): string
    {
        $ref = new ReflectionMethod(Auth::class, $method);
        $file = file($ref->getFileName());
        $start = $ref->getStartLine() - 1;
        $end = $ref->getEndLine();
        return implode('', array_slice($file, $start, $end - $start));
    }
}
