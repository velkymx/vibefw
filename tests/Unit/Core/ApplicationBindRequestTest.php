<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Core\Application;
use Fw\Core\Container;
use Fw\Core\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Item #1: HttpKernel::handle() processes a per-request Request, but the
 * boot-time Request bound by Application is never replaced. Anything that
 * resolves Request::class from the container (executeInFiber, middleware
 * via $app->request) sees stale boot-time data instead of the live
 * request.
 *
 * The fix exposes Application::bindRequest(Request) that updates both the
 * public-readonly property and the container singleton, so the kernel can
 * swap in the live request at the start of every handle() call.
 */
final class ApplicationBindRequestTest extends TestCase
{
    #[Test]
    public function bindRequestMethodExists(): void
    {
        $this->assertTrue(
            method_exists(Application::class, 'bindRequest'),
            'Application must expose bindRequest(Request) so HttpKernel can '
            . 'swap the boot-time placeholder for the live per-request object.',
        );
    }

    #[Test]
    public function bindRequestUpdatesPropertyAndContainer(): void
    {
        $app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();

        $container = new Container();
        $boot = new Request(uri: '/__boot__');

        $ref = new ReflectionClass($app);
        $ref->getProperty('container')->setValue($app, $container);
        $ref->getProperty('request')->setValue($app, $boot);
        $container->instance(Request::class, $boot);

        $live = new Request(uri: '/live/path');
        $app->bindRequest($live);

        $this->assertSame($live, $app->request, 'bindRequest must update the public property.');
        $this->assertSame(
            $live,
            $container->get(Request::class),
            'bindRequest must rebind the container singleton so resolutions see the live request.',
        );
    }
}
