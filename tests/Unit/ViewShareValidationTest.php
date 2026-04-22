<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Cache\MemoryCache;
use Fw\Core\Router;
use Fw\Core\View;
use Fw\Security\Csrf;
use Fw\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Item #11: validateViewData only ran on each render()/include() call, so a
 * reserved or malformed name slipped into View::share() would sit in shared
 * state until rendering and only surface then. Shared keys must be validated
 * at the point of sharing — it's the only way a caller can tell a bad key
 * from a bad value without attempting a render.
 */
final class ViewShareValidationTest extends TestCase
{
    private function makeView(): View
    {
        $cache = new MemoryCache();
        $router = new Router();
        $csrf = new Csrf(static fn () => null);
        return new View(sys_get_temp_dir(), $cache, $router, $csrf);
    }

    #[Test]
    public function shareRejectsReservedName(): void
    {
        $view = $this->makeView();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved');
        $view->share('e', 'clobbered');
    }

    #[Test]
    public function shareRejectsInvalidVariableName(): void
    {
        $view = $this->makeView();
        $this->expectException(InvalidArgumentException::class);
        $view->share('not-a-var', 'x');
    }

    #[Test]
    public function shareAcceptsNormalKey(): void
    {
        $view = $this->makeView();
        $this->expectNotToPerformAssertions();
        $view->share('currentUser', 'alice');
    }
}
