<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Item C6 (part 2) — Route cache previously written via `var_export`
 * and consumed via `require $cacheFile`. Any attacker who could write
 * to `storage/cache/` (shared hosting, misconfigured perms, deploy
 * symlink swap) had remote code execution at the next bootstrap.
 *
 * Post-fix the cache is JSON. The file content is never `require`'d,
 * so it carries no executable surface — even if attacker-controlled
 * bytes land in the cache file, the worst outcome is a JSON decode
 * failure and a fresh route-tree build.
 */
final class RouterCacheJsonFormatTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/fw_router_cache_json_' . bin2hex(random_bytes(4)) . '.cache';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }

    #[Test]
    public function saveCacheWritesJsonNotPhp(): void
    {
        $router = new Router();
        $router->setCacheFile($this->cacheFile);
        $router->get('/users', ['App\\Controllers\\UserController', 'index']);

        $this->assertTrue($router->saveCache());
        $this->assertFileExists($this->cacheFile);

        $content = file_get_contents($this->cacheFile);
        $this->assertNotFalse($content);

        $this->assertStringStartsNotWith(
            '<?php',
            $content,
            'cache file must NOT be PHP — `<?php` content is what produces the RCE-on-include surface.',
        );

        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded, 'cache content must decode as JSON');
        $this->assertArrayHasKey('routes', $decoded);
        $this->assertArrayHasKey('named', $decoded);
    }

    #[Test]
    public function loadCachePopulatesRoutesFromJson(): void
    {
        $writer = new Router();
        $writer->setCacheFile($this->cacheFile);
        $writer->get('/users/{id:id}', ['App\\Controllers\\UserController', 'show'], 'users.show');
        $writer->saveCache();

        $reader = new Router();
        $reader->setCacheFile($this->cacheFile);

        $this->assertTrue($reader->loadCache(), 'loadCache must return true on a freshly written JSON cache');

        $result = $reader->dispatch('GET', '/users/42');
        $this->assertTrue($result->isOk());

        $match = $result->unwrapOr(null);
        $this->assertSame(['App\\Controllers\\UserController', 'show'], $match->handler);
        $this->assertSame(['id' => '42'], $match->params);
    }

    #[Test]
    public function loadCacheRefusesPhpHeaderContent(): void
    {
        // Simulate a leftover legacy `var_export` cache file or an
        // attacker-planted PHP payload landing at the cache path.
        // The new loader must not `require` it; just treat as miss.
        file_put_contents(
            $this->cacheFile,
            "<?php /* attacker payload */ throw new \\RuntimeException('cache should not execute'); ?>",
        );

        $router = new Router();
        $router->setCacheFile($this->cacheFile);

        $this->assertFalse(
            $router->loadCache(),
            'loadCache must refuse non-JSON content (e.g. a legacy `<?php` cache) instead of executing it.',
        );
    }

    #[Test]
    public function loadCacheReturnsFalseOnGarbageContent(): void
    {
        file_put_contents($this->cacheFile, 'not valid json {{');

        $router = new Router();
        $router->setCacheFile($this->cacheFile);

        $this->assertFalse($router->loadCache());
    }
}
