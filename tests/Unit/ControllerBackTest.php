<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Application;
use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionProperty;

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
 *
 * H12: back() must read headers from the Request object, not
 * from $_SERVER directly (worker-mode safety).
 */
final class ControllerBackTest extends TestCase
{
    private BackTestableController $controller;

    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = new Request(
            headers: ['host' => 'myapp.test'],
        );

        $app = (new ReflectionClass(Application::class))
            ->newInstanceWithoutConstructor();

        $reqProp = new ReflectionProperty(Application::class, 'request');
        $reqProp->setValue($app, $this->request);

        $rc = new ReflectionClass(BackTestableController::class);
        /** @var BackTestableController $ctrl */
        $ctrl = $rc->newInstanceWithoutConstructor();

        $appProp = new ReflectionProperty(Controller::class, 'app');
        $appProp->setValue($ctrl, $app);

        $this->controller = $ctrl;
    }

    #[Test]
    public function backRedirectsToSlashWhenNoReferer(): void
    {
        $response = $this->controller->exposeBack();

        $this->assertSame('/', $this->locationOf($response));
    }

    #[Test]
    public function backRedirectsToSameOriginReferer(): void
    {
        $this->request = new Request(
            headers: ['host' => 'myapp.test', 'referer' => 'http://myapp.test/dashboard'],
        );
        $this->refreshAppRequest();

        $response = $this->controller->exposeBack();

        $this->assertSame('http://myapp.test/dashboard', $this->locationOf($response));
    }

    #[Test]
    public function backRedirectsToPathOnlyReferer(): void
    {
        $this->request = new Request(
            headers: ['host' => 'myapp.test', 'referer' => '/some/path?foo=bar'],
        );
        $this->refreshAppRequest();

        $response = $this->controller->exposeBack();

        $this->assertSame('/some/path?foo=bar', $this->locationOf($response));
    }

    /** @return array<string, array{string}> */
    public static function externalRefererProvider(): array
    {
        return [
            'evil domain' => ['https://evil.com/steal'],
            'subdomain attack' => ['https://evil.myapp.test/phish'],
            'protocol-relative' => ['//evil.com/phish'],
            'open redirect attempt' => ['https://evil.com/fake?url=myapp.test'],
            'javascript xss' => ['javascript:alert(1)'],
            'data uri' => ['data:text/html,<script>alert(1)</script>'],
            'vbscript xss' => ['vbscript:msgbox(1)'],
            'backslash protocol' => ['/\\evil.com/path'],
            'reversed protocol' => ['\\/evil.com/path'],
        ];
    }

    #[Test]
    #[DataProvider('externalRefererProvider')]
    public function backRefusesExternalRefererAndFallsBackToSlash(string $maliciousReferer): void
    {
        $this->request = new Request(
            headers: ['host' => 'myapp.test', 'referer' => $maliciousReferer],
        );
        $this->refreshAppRequest();

        $response = $this->controller->exposeBack();

        $location = $this->locationOf($response);
        $this->assertSame(
            '/',
            $location,
            "back() must not follow external referer: $maliciousReferer"
        );
    }

    private function refreshAppRequest(): void
    {
        $rc = new ReflectionClass(Application::class);
        $app = $rc->newInstanceWithoutConstructor();

        $reqProp = new ReflectionProperty(Application::class, 'request');
        $reqProp->setValue($app, $this->request);

        $appProp = new ReflectionProperty(Controller::class, 'app');
        $appProp->setValue($this->controller, $app);
    }

    private function locationOf(Response $response): string
    {
        $headers = $response->getHeaders();
        return $headers['Location'] ?? '';
    }
}
