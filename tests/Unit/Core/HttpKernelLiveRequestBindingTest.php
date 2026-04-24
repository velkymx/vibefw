<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Core\HttpKernel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Item #1: Locks the structural fix into HttpKernel::handle().
 *
 * The kernel must call Application::bindRequest($request) at the start of
 * every handle() invocation, before the pipeline runs. Otherwise middleware
 * and lifecycle hooks that resolve Request::class from the container, or
 * read $app->request, observe the stale boot-time placeholder.
 */
final class HttpKernelLiveRequestBindingTest extends TestCase
{
    #[Test]
    public function handleRebindsLiveRequestOnApplication(): void
    {
        $method = new ReflectionMethod(HttpKernel::class, 'handle');

        $file = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($file, $start, $end - $start));

        $this->assertStringContainsString(
            '$this->app->bindRequest($request)',
            $body,
            'HttpKernel::handle() must rebind the Application/container Request '
            . 'to the live per-request object before the pipeline executes.',
        );
    }

    #[Test]
    public function rebindHappensBeforePipelineExecution(): void
    {
        $method = new ReflectionMethod(HttpKernel::class, 'handle');

        $file = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $body = implode('', array_slice($file, $start, $end - $start));

        $bindPos = strpos($body, '$this->app->bindRequest($request)');
        $dispatchPos = strpos($body, '$this->router->dispatch(');

        $this->assertNotFalse($bindPos, 'Expected bindRequest call in HttpKernel::handle().');
        $this->assertNotFalse($dispatchPos, 'Expected router dispatch in HttpKernel::handle().');
        $this->assertLessThan(
            $dispatchPos,
            $bindPos,
            'bindRequest must run before router dispatch so middleware and route '
            . 'handlers see the live request, not the boot placeholder.',
        );
    }
}
