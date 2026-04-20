<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Cache\Cache;
use Fw\Cache\MemoryCache;
use Fw\Core\Router;
use Fw\Core\View;
use Fw\Core\ViewCache;
use Fw\Security\Csrf;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ViewFragmentCacheStorageTest extends TestCase
{
    private string $viewDir;

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->viewDir = sys_get_temp_dir() . '/fw_view_storage_' . uniqid();
        $this->cacheDir = sys_get_temp_dir() . '/fw_view_cache_' . uniqid();
        mkdir($this->viewDir, 0o755, true);
        mkdir($this->cacheDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->viewDir . '/*.php') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->viewDir);

        foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
            is_dir($f) ? self::rrmdir($f) : unlink($f);
        }
        rmdir($this->cacheDir);
    }

    private static function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? self::rrmdir($f) : unlink($f);
        }
        rmdir($dir);
    }

    private function makeView(): View
    {
        $cache = new Cache(new MemoryCache());
        $router = new Router();
        $csrf = new Csrf(fn () => null);
        $view = new View($this->viewDir, $cache, $router, $csrf);
        $view->enableCache($this->cacheDir);
        return $view;
    }

    #[Test]
    public function endCachePopulatesCacheAndOutputsContent(): void
    {
        file_put_contents($this->viewDir . '/fragment_store.php', <<<'PHP'
<?php if ($cache('my_key', 60)): ?>CACHED_CONTENT<?php $endCache(); endif; ?>
PHP);

        $view = $this->makeView();
        $output = $view->render('fragment_store', []);

        $this->assertStringContainsString('CACHED_CONTENT', $output);

        $rc = new ReflectionClass(View::class);
        $prop = $rc->getProperty('viewCache');
        /** @var ViewCache $viewCache */
        $viewCache = $prop->getValue($view);

        $this->assertSame('CACHED_CONTENT', $viewCache->get('fragment_my_key'));
    }

    #[Test]
    public function secondRenderReusesCachedFragmentWithoutReRendering(): void
    {
        $viewFile = $this->viewDir . '/fragment_reuse.php';
        file_put_contents($viewFile, <<<'PHP'
<?php if ($cache('reuse_key', 60)): ?>FIRST_RENDER<?php $endCache(); endif; ?>
PHP);

        $view = $this->makeView();
        $first = $view->render('fragment_reuse', []);
        $this->assertStringContainsString('FIRST_RENDER', $first);

        // Change the view file; cached fragment must still be served.
        file_put_contents($viewFile, <<<'PHP'
<?php if ($cache('reuse_key', 60)): ?>SECOND_RENDER<?php $endCache(); endif; ?>
PHP);

        $second = $view->render('fragment_reuse', []);
        $this->assertStringContainsString('FIRST_RENDER', $second);
        $this->assertStringNotContainsString('SECOND_RENDER', $second);
    }

    #[Test]
    public function endCacheDoesNotUseObGetFlush(): void
    {
        $source = file_get_contents((new ReflectionClass(View::class))->getFileName());
        self::assertIsString($source);
        $this->assertStringNotContainsString(
            'ob_get_flush',
            $source,
            'View::endCache() must use ob_get_clean() + echo to avoid leaking output before cache write.',
        );
    }
}
