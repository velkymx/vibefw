<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Auth\Auth;
use Fw\Support\Option;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuthUserOptionTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure no session/context residue
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    #[Test]
    public function userReturnsOptionType(): void
    {
        // Auth::user() must return Option<Model>, not ?Model
        $result = Auth::user();
        $this->assertInstanceOf(Option::class, $result);
    }

    #[Test]
    public function userReturnsNoneWhenNotAuthenticated(): void
    {
        $result = Auth::user();
        $this->assertTrue($result->isNone());
    }
}
