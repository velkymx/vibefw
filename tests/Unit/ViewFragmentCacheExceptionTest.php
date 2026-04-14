<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Cache\Cache;
use Fw\Cache\MemoryCache;
use Fw\Core\View;
use Fw\Core\Router;
use Fw\Security\Csrf;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ViewFragmentCacheExceptionTest extends TestCase
{
    private string $viewDir;

    protected function setUp(): void
    {
        $this->viewDir = sys_get_temp_dir() . '/fw_view_test_' . uniqid();
        mkdir($this->viewDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // Clean up view files
        foreach (glob($this->viewDir . '/*.php') as $f) {
            unlink($f);
        }
        rmdir($this->viewDir);
    }

    private function makeView(): View
    {
        $cache = new Cache(new MemoryCache());
        $router = new Router();
        $csrf = new Csrf(fn () => null);
        return new View($this->viewDir, $cache, $router, $csrf);
    }

    #[Test]
    public function exceptionInsideCachedFragmentDoesNotLeakOutputBuffer(): void
    {
        $obLevelBefore = ob_get_level();

        file_put_contents($this->viewDir . '/fragment_ex.php', <<<'PHP'
<?php if ($cache('test_key')): ?>
    <?php throw new \RuntimeException('boom inside fragment'); ?>
<?php $endCache(); endif; ?>
PHP);

        $view = $this->makeView();

        try {
            $view->render('fragment_ex', []);
        } catch (RuntimeException $e) {
            $this->assertSame('boom inside fragment', $e->getMessage());
        }

        // ob level must be restored — no leaked ob_start() calls
        $this->assertSame($obLevelBefore, ob_get_level(), 'ob level leaked after exception in cached fragment');
    }

    #[Test]
    public function normalFragmentCacheWorksWithoutException(): void
    {
        file_put_contents($this->viewDir . '/fragment_ok.php', <<<'PHP'
<?php if ($cache('my_fragment', 60)): ?>Hello fragment<?php $endCache(); endif; ?>
PHP);

        $view = $this->makeView();
        $output = $view->render('fragment_ok', []);
        $this->assertStringContainsString('Hello fragment', $output);
    }
}
