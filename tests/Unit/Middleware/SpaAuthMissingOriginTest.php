<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Middleware;

use Fw\Core\Request;
use Fw\Middleware\SpaAuthMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Item C8 — `validateOrigin()` missing-origin branch.
 *
 * Pre-fix the fail-open path read:
 *
 *     return ! ($env === 'production')
 *
 *         // multi-line comment between expression and ;
 *         ;
 *
 * Two problems: (a) any maintainer adding a line after the `return`
 * silently changes behaviour because the `;` is detached; (b) the
 * negation-of-equality is easy to misread, and the existing tests
 * never exercised production vs non-production missing-Origin.
 *
 * Post-fix the branch is an explicit `if ($isProduction) return false;
 * return true;` and these tests pin the contract:
 *   - production + missing Origin/Referer  → reject (false)
 *   - local/development + missing headers  → allow (true)
 *   - default APP_ENV (unset)              → reject (treated as prod)
 */
final class SpaAuthMissingOriginTest extends TestCase
{
    private ?string $previousEnv = null;
    private ?string $previousGetenv = null;
    private bool $hadEnv = false;
    private bool $hadGetenv = false;

    protected function setUp(): void
    {
        $this->hadEnv = array_key_exists('APP_ENV', $_ENV);
        $this->previousEnv = $this->hadEnv ? (string) $_ENV['APP_ENV'] : null;
        $this->hadGetenv = getenv('APP_ENV') !== false;
        $this->previousGetenv = $this->hadGetenv ? getenv('APP_ENV') : null;
    }

    protected function tearDown(): void
    {
        if ($this->hadEnv) {
            $_ENV['APP_ENV'] = $this->previousEnv;
        } else {
            unset($_ENV['APP_ENV']);
        }
        if ($this->hadGetenv) {
            putenv("APP_ENV={$this->previousGetenv}");
        } else {
            putenv('APP_ENV');
        }
    }

    #[Test]
    public function productionRejectsRequestWithoutOriginOrReferer(): void
    {
        $_ENV['APP_ENV'] = 'production';
        putenv('APP_ENV=production');
        $this->assertFalse(
            $this->callValidateOrigin(headers: []),
            'production + missing Origin/Referer must reject — defense against non-browser clients '
            . 'pretending to be the SPA.',
        );
    }

    #[Test]
    public function localAllowsRequestWithoutOriginOrReferer(): void
    {
        $_ENV['APP_ENV'] = 'local';
        putenv('APP_ENV=local');
        $this->assertTrue(
            $this->callValidateOrigin(headers: []),
            'local env must allow no-Origin requests so curl/Postman can hit the SPA endpoints during dev.',
        );
    }

    #[Test]
    public function developmentAllowsRequestWithoutOriginOrReferer(): void
    {
        $_ENV['APP_ENV'] = 'development';
        putenv('APP_ENV=development');
        $this->assertTrue(
            $this->callValidateOrigin(headers: []),
            'development env must also allow no-Origin (alias of local).',
        );
    }

    #[Test]
    public function unsetAppEnvDefaultsToProductionAndRejects(): void
    {
        unset($_ENV['APP_ENV']);
        putenv('APP_ENV');
        $this->assertFalse(
            $this->callValidateOrigin(headers: []),
            'unset APP_ENV must default to production posture (reject), not fail-open.',
        );
    }

    /**
     * Build a SpaAuthMiddleware whose config has an spa_domains
     * whitelist — this routes execution into the missing-Origin
     * branch we want to exercise (the empty-allowlist branch
     * has its own production/local logic earlier in the method).
     *
     * @param array<string,string> $headers
     */
    private function callValidateOrigin(array $headers): bool
    {
        $middleware = (new ReflectionClass(SpaAuthMiddleware::class))->newInstanceWithoutConstructor();

        $configRef = new ReflectionClass($middleware);
        $configRef->getProperty('config')->setValue($middleware, [
            'spa_domains' => ['example.com'],
        ]);

        $request = new Request(
            uri: '/api/data',
            method: 'GET',
            server: [],
            query: [],
            headers: $headers,
        );

        $method = new ReflectionMethod(SpaAuthMiddleware::class, 'validateOrigin');
        return (bool) $method->invoke($middleware, $request);
    }
}
