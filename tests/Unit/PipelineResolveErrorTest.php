<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Application;
use Fw\Core\Container;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Middleware\MiddlewareInterface;
use Fw\Middleware\Pipeline;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

/**
 * A middleware whose constructor requires an unresolvable parameter so
 * Container::make() will throw.
 */
final class BrokenMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $requiredParam)
    {
    }

    public function handle(Request $request, callable $next): Response|string|array
    {
        return $next($request);
    }
}

/**
 * P-04: Pipeline::resolveString() must wrap Container::make() exceptions
 * in an InvalidArgumentException with a clear, actionable message.
 */
final class PipelineResolveErrorTest extends TestCase
{
    private function appWithAlias(string $alias, string $class): Application
    {
        $cfg          = new stdClass();
        $cfg->aliases = [$alias => $class];

        $container = new Container();
        $container->instance('middleware.config', $cfg);

        $app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        (new ReflectionClass($app))->getProperty('container')->setValue($app, $container);

        return $app;
    }

    // -------------------------------------------------------------------------

    #[Test]
    public function resolvingUnresolvableMiddlewareThrowsInvalidArgumentException(): void
    {
        // BrokenMiddleware requires $requiredParam which Container cannot resolve.
        $app = $this->appWithAlias('broken', BrokenMiddleware::class);
        $pipeline = (new Pipeline($app))->through(['broken']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/broken|BrokenMiddleware/i');

        $pipeline->then(fn (Request $r) => new Response(), new Request());
    }

    #[Test]
    public function resolvingUnknownAliasThrowsInvalidArgumentException(): void
    {
        $container = new Container();
        $app       = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        (new ReflectionClass($app))->getProperty('container')->setValue($app, $container);

        $pipeline = (new Pipeline($app))->through(['nonexistent_alias']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nonexistent_alias/');

        $pipeline->then(fn (Request $r) => new Response(), new Request());
    }

    #[Test]
    public function resolvingNonMiddlewareClassThrowsInvalidArgumentException(): void
    {
        // stdClass implements no interface, so the post-make check must throw.
        $app = $this->appWithAlias('bad', \stdClass::class);
        $pipeline = (new Pipeline($app))->through(['bad']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/MiddlewareInterface/');

        $pipeline->then(fn (Request $r) => new Response(), new Request());
    }
}
