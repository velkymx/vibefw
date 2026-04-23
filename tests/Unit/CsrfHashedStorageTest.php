<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Security\Csrf;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Item #19: previously the CSRF secret was stored in `$_SESSION` in
 * plaintext. A session file leak (shared hosting, misconfigured storage,
 * an unrelated session-read vuln) therefore leaked the CSRF token
 * directly, letting an attacker forge requests.
 *
 * Fix: with an APP_KEY-derived server secret, the session stores a random
 * seed and the public CSRF token is `hash_hmac('sha256', seed, secret)`.
 * Reading the session reveals only the seed — the token cannot be
 * reconstructed without the secret.
 *
 * Backward compat: constructing Csrf with no secret keeps the legacy
 * plaintext-in-session behaviour so existing callsites and tests keep
 * working.
 */
final class CsrfHashedStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    /** @return list<string> */
    private function sessionStringValues(): array
    {
        return array_values(array_filter($_SESSION, 'is_string'));
    }

    #[Test]
    public function hmacModeDoesNotStorePlaintextTokenInSession(): void
    {
        $csrf = new Csrf(static fn () => null, 'server-secret-with-enough-entropy-abc');
        $token = $csrf->getToken();

        $this->assertNotSame('', $token);
        $this->assertNotContains(
            $token,
            $this->sessionStringValues(),
            'session must not contain the plaintext CSRF token when a secret is configured',
        );
    }

    #[Test]
    public function hmacModeStoresASeedNotTheToken(): void
    {
        $csrf = new Csrf(static fn () => null, 'server-secret-with-enough-entropy-abc');
        $token = $csrf->getToken();

        // Session must contain SOMETHING (the seed) — otherwise we couldn't
        // reproduce the same token on the next request.
        $this->assertNotSame([], $this->sessionStringValues());

        // And that stored value is NOT the token.
        foreach ($this->sessionStringValues() as $stored) {
            $this->assertNotSame($token, $stored);
        }
    }

    #[Test]
    public function hmacModeTokenIsStableAcrossCalls(): void
    {
        $csrf = new Csrf(static fn () => null, 'server-secret-with-enough-entropy-abc');
        $this->assertSame($csrf->getToken(), $csrf->getToken());
    }

    #[Test]
    public function hmacModeRegenerateRotatesTheSeedAndToken(): void
    {
        $csrf = new Csrf(static fn () => null, 'server-secret-with-enough-entropy-abc');

        $first = $csrf->getToken();
        $seedBefore = $this->sessionStringValues();

        $second = $csrf->regenerateToken();
        $seedAfter = $this->sessionStringValues();

        $this->assertNotSame($first, $second);
        $this->assertNotSame($seedBefore, $seedAfter);
    }

    #[Test]
    public function hmacModeValidateAcceptsLiveToken(): void
    {
        $csrf = new Csrf(static fn () => null, 'server-secret-with-enough-entropy-abc');
        $token = $csrf->getToken();

        $this->assertTrue($csrf->validate($token));
    }

    #[Test]
    public function hmacModeValidateRejectsRawSeed(): void
    {
        $csrf = new Csrf(static fn () => null, 'server-secret-with-enough-entropy-abc');
        $csrf->getToken();

        foreach ($this->sessionStringValues() as $stored) {
            $this->assertFalse(
                $csrf->validate($stored),
                'submitting the raw session value (seed) must not validate — the attacker who reads the session gets the seed, not the token',
            );
        }
    }

    #[Test]
    public function hmacModeValidateRejectsTokenBuiltWithDifferentSecret(): void
    {
        $csrf = new Csrf(static fn () => null, 'server-secret-one-entropy-abc-abc');
        $realToken = $csrf->getToken();

        // Simulate an attacker who read the session and tried to forge a
        // token with a guessed / different secret.
        $seed = $this->sessionStringValues()[0];
        $forged = hash_hmac('sha256', $seed, 'attacker-guess');

        $this->assertTrue($csrf->validate($realToken));
        $this->assertFalse($csrf->validate($forged));
    }

    #[Test]
    public function legacyModeWithoutSecretStillWorks(): void
    {
        // Backward compat: existing callsites pass no secret.
        $csrf = new Csrf(static fn () => null);
        $token = $csrf->getToken();

        $this->assertNotSame('', $token);
        $this->assertTrue($csrf->validate($token));
        $this->assertFalse($csrf->validate('wrong'));
    }

    #[Test]
    public function hmacModeHashCollisionTimingSafe(): void
    {
        // hash_equals is used for both legacy and HMAC paths — sanity
        // check that a wrong-length candidate is rejected cleanly.
        $csrf = new Csrf(static fn () => null, 'server-secret-with-enough-entropy-abc');
        $csrf->getToken();

        $this->assertFalse($csrf->validate(''));
        $this->assertFalse($csrf->validate('short'));
    }
}
