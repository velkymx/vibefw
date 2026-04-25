<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Core\Controller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Item H12 — Controller::back() must read Referer and Host from the
 * Request object, not from $_SERVER directly. In FrankenPHP worker mode,
 * $_SERVER is shared global state that is not reset per-fiber — two
 * concurrent requests can see each other's headers.
 */
final class ControllerBackNoSuperglobalTest extends TestCase
{
    #[Test]
    public function backDoesNotReadServerSuperglobalForReferer(): void
    {
        $body = $this->methodBody('back');

        $this->assertStringNotContainsString(
            "\$_SERVER['HTTP_REFERER']",
            $body,
            'Controller::back() must not read $_SERVER[HTTP_REFERER] directly — use $this->app->request->header() instead.',
        );
    }

    #[Test]
    public function backDoesNotReadServerSuperglobalForHost(): void
    {
        $body = $this->methodBody('back');

        $this->assertStringNotContainsString(
            "\$_SERVER['HTTP_HOST']",
            $body,
            'Controller::back() must not read $_SERVER[HTTP_HOST] directly — use $this->app->request->header() instead.',
        );
    }

    #[Test]
    public function backReadsRefererFromRequestObject(): void
    {
        $body = $this->methodBody('back');

        $this->assertStringContainsString(
            "->header('referer')",
            $body,
            'Controller::back() must read the Referer header via $this->app->request->header("referer").',
        );
    }

    #[Test]
    public function backReadsHostFromRequestObject(): void
    {
        $body = $this->methodBody('back');

        $this->assertStringContainsString(
            "->header('host')",
            $body,
            'Controller::back() must read the Host header via $this->app->request->header("host").',
        );
    }

    private function methodBody(string $method): string
    {
        $ref = new ReflectionMethod(Controller::class, $method);
        $file = file($ref->getFileName());
        $start = $ref->getStartLine() - 1;
        $end = $ref->getEndLine();
        return implode('', array_slice($file, $start, $end - $start));
    }
}
