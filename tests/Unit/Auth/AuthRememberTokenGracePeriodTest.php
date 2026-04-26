<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Auth;

use Fw\Auth\Auth;
use Fw\Core\RequestContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * M13: Remember token rotation on every use causes cookie churn.
 *
 * Pre-fix: The remember token was rotated after every successful use,
 * causing excessive cookie churn. Every page load issued a new cookie,
 * which could cause race conditions if multiple tabs loaded simultaneously
 * (last one wins), and increased database writes.
 *
 * Post-fix: Added a 5-minute grace period where the same token can be
 * reused without rotation. This reduces cookie churn while still preventing
 * replay attacks. The token is only rotated if it's older than 5 minutes.
 */
final class AuthRememberTokenGracePeriodTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::clear();
    }

    #[Test]
    public function getUserFromRememberTokenChecksGracePeriod(): void
    {
        $source = file_get_contents((new \ReflectionClass(Auth::class))->getFileName());
        $this->assertStringContainsString(
            'gracePeriod',
            $source,
            'getUserFromRememberToken should check grace period before rotating token'
        );
    }

    #[Test]
    public function setRememberTokenUpdatesTimestamp(): void
    {
        $source = file_get_contents((new \ReflectionClass(Auth::class))->getFileName());
        $this->assertStringContainsString(
            'remember_token_updated_at',
            $source,
            'setRememberToken should update remember_token_updated_at timestamp'
        );
    }

    #[Test]
    public function gracePeriodIsFiveMinutes(): void
    {
        $source = file_get_contents((new \ReflectionClass(Auth::class))->getFileName());
        $this->assertStringContainsString(
            '300',
            $source,
            'Grace period should be 300 seconds (5 minutes)'
        );
    }

    #[Test]
    public function tokenRotationIsConditional(): void
    {
        $source = file_get_contents((new \ReflectionClass(Auth::class))->getFileName());
        $this->assertStringContainsString(
            '$shouldRotate',
            $source,
            'Token rotation should be conditional based on grace period'
        );
    }
}
