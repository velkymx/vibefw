<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Exposes Controller::back() for testing.
 */
final class BackTestableController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->json([]);
    }

    public function exposeBack(): Response
    {
        return $this->back();
    }
}

/**
 * Tests for Controller::back() — must be same-origin only.
 *
 * C2: HTTP_REFERER is attacker-controlled. Without validation,
 * back() can redirect users off-site (open redirect).
 */
final class ControllerBackTest extends TestCase
{
    private BackTestableController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['HTTP_HOST'] = 'myapp.test';

        $rc = new ReflectionClass(BackTestableController::class);
        /** @var BackTestableController $ctrl */
        $ctrl = $rc->newInstanceWithoutConstructor();
        $this->controller = $ctrl;
    }

    #[Test]
    public function backRedirectsToSlashWhenNoReferer(): void
    {
        unset($_SERVER['HTTP_REFERER']);

        $response = $this->controller->exposeBack();

        $this->assertSame('/', $this->locationOf($response));
    }

    #[Test]
    public function backRedirectsToSameOriginReferer(): void
    {
        $_SERVER['HTTP_REFERER'] = 'http://myapp.test/dashboard';

        $response = $this->controller->exposeBack();

        $this->assertSame('http://myapp.test/dashboard', $this->locationOf($response));
    }

    #[Test]
    public function backRedirectsToPathOnlyReferer(): void
    {
        $_SERVER['HTTP_REFERER'] = '/some/path?foo=bar';

        $response = $this->controller->exposeBack();

        $this->assertSame('/some/path?foo=bar', $this->locationOf($response));
    }

    /** @return array<string, array{string}> */
    public static function externalRefererProvider(): array
    {
        return [
            'evil domain'           => ['https://evil.com/steal'],
            'subdomain attack'      => ['https://evil.myapp.test/phish'],
            'protocol-relative'     => ['//evil.com/phish'],
            'open redirect attempt' => ['https://evil.com/fake?url=myapp.test'],
        ];
    }

    #[Test]
    #[DataProvider('externalRefererProvider')]
    public function backRefusesExternalRefererAndFallsBackToSlash(string $maliciousReferer): void
    {
        $_SERVER['HTTP_REFERER'] = $maliciousReferer;

        $response = $this->controller->exposeBack();

        $location = $this->locationOf($response);
        $this->assertSame(
            '/',
            $location,
            "back() must not follow external referer: $maliciousReferer"
        );
    }

    private function locationOf(Response $response): string
    {
        $headers = $response->getHeaders();
        return $headers['Location'] ?? '';
    }
}
