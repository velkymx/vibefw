<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Auth\ApiToken;
use Fw\Auth\Contracts\RevocableTokenInterface;
use Fw\Auth\TokenGuard;
use Fw\Core\Request;
use Fw\Core\RequestContext;
use Fw\Model\Model;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * Item #16: TokenGuard::authenticateToken() assumed the configured token
 * model implemented RevocableTokenInterface and exposed a user()
 * relationship, but setTokenModel() accepts any class string. A
 * misconfigured app would crash with a fatal "method not found" on
 * touchLastUsed() or user() instead of failing authentication cleanly.
 *
 * Lock in: auth returns null (not fatal) when the token model does not
 * satisfy the required contract, and the interface guard runs before
 * any interface-specific method call.
 */
final class TokenGuardContractTest extends TestCase
{
    private string $originalModel;

    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::create(new Request());
        $this->originalModel = TokenGuardContractTestOriginalModel::class;
        // Capture the current model so we can restore after the test.
    }

    protected function tearDown(): void
    {
        TokenGuard::logout();
        // Reset the token model for downstream tests.
        ApiToken::setTokenModel(\App\Models\PersonalAccessToken::class);
        parent::tearDown();
    }

    #[Test]
    public function authenticateTokenReturnsNullWhenTokenModelLacksContract(): void
    {
        // The stub token model returns a concrete token that is NOT
        // RevocableTokenInterface — the guard must decline auth instead
        // of invoking touchLastUsed() on a type that does not declare it.
        ApiToken::setTokenModel(NonRevocableTokenStub::class);

        $this->assertNull(TokenGuard::authenticateToken('anything'));
    }

    #[Test]
    public function authenticateTokenEnforcesInterfaceBeforeInvokingContractMethods(): void
    {
        $method = new ReflectionMethod(TokenGuard::class, 'authenticateToken');
        $body = implode('', array_slice(
            file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        // Anchor each search on syntax that cannot appear inside comments:
        // actual invocations / expressions, not bare identifier mentions.
        $interfacePos = strpos($body, '$accessToken instanceof RevocableTokenInterface');
        $touchPos = strpos($body, '$accessToken->touchLastUsed(');
        $userPos = strpos($body, '$accessToken->user()');

        $this->assertNotFalse($interfacePos, 'expected an instanceof RevocableTokenInterface guard in authenticateToken()');
        $this->assertNotFalse($touchPos, 'expected touchLastUsed() call in authenticateToken()');
        $this->assertNotFalse($userPos, 'expected ->user() relation call in authenticateToken()');
        $this->assertLessThan(
            $touchPos,
            $interfacePos,
            'interface guard must precede touchLastUsed() — otherwise a misconfigured token model crashes with a fatal error.'
        );
        $this->assertLessThan(
            $userPos,
            $interfacePos,
            'interface guard must precede the ->user() relationship call.'
        );
    }
}

/**
 * Minimal stub token model that ApiToken::find() can successfully
 * locate, but which does NOT implement RevocableTokenInterface.
 * Used to prove TokenGuard declines auth instead of fataling.
 */
final class NonRevocableTokenStub extends Model
{
    protected static ?string $table = 'nonrevocable_stub_tokens';

    public static function findToken(string $hashed): ?static
    {
        return new static();
    }

    public function isExpired(): bool
    {
        return false;
    }
}

/**
 * Placeholder referenced in setUp() for symmetry; the real token model
 * reset lives in tearDown() which pins back to PersonalAccessToken.
 */
final class TokenGuardContractTestOriginalModel extends Model
{
}
