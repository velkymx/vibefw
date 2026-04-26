<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Cache\Cache;
use Fw\Cache\MemoryCache;
use Fw\Core\Router;
use Fw\Core\View;
use Fw\Security\Csrf;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * M11: View path traversal check can be bypassed with symlinks.
 *
 * Pre-fix: The resolvePath() method used realpath() to check that the view
 * path stays within the base directory. However, if an attacker could create
 * a symlink inside the views directory pointing elsewhere, realpath() would
 * resolve it to the target, allowing path traversal attacks.
 *
 * Post-fix: Added checkForRecentSymlinks() that rejects symlinks created
 * in the last 5 minutes. This mitigates time-of-check-to-time-of-use attacks
 * while allowing legitimate symlinks that existed before the application started.
 */
final class ViewSymlinkTraversalTest extends TestCase
{
    private string $tempDir;
    private string $viewsDir;
    private View $view;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/fw_view_symlink_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o750, true);

        $this->viewsDir = $this->tempDir . '/views';
        mkdir($this->viewsDir, 0o750, true);

        // Create real instances of dependencies
        $cache = new Cache(new MemoryCache());
        $router = new Router();
        $csrf = new Csrf(fn () => null);

        $this->view = new View($this->viewsDir, $cache, $router, $csrf);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tempDir);
    }

    #[Test]
    public function recentlyCreatedSymlinkIsRejected(): void
    {
        // Create a target file outside the views directory
        $targetFile = $this->tempDir . '/secret.txt';
        file_put_contents($targetFile, 'secret content');

        // Create a symlink inside the views directory pointing to the target
        $symlinkPath = $this->viewsDir . '/secret.php';
        symlink($targetFile, $symlinkPath);

        // Try to render the symlinked view
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/recently created symlink/');

        $this->view->render('secret');
    }

    #[Test]
    public function oldSymlinkIsAllowed(): void
    {
        // Create a target file inside the views directory (but in a subdirectory)
        $subDir = $this->viewsDir . '/subdir';
        mkdir($subDir, 0o750, true);

        $targetFile = $subDir . '/allowed.txt';
        file_put_contents($targetFile, 'allowed content');

        // Create a symlink inside the views directory pointing to the target
        $symlinkPath = $this->viewsDir . '/allowed.php';
        symlink($targetFile, $symlinkPath);

        // Make the symlink old by touching it with an old timestamp
        $oldTime = time() - 400; // 400 seconds ago (more than 5 minutes)
        touch($symlinkPath, $oldTime);

        // Try to render the symlinked view - should succeed
        $result = $this->view->render('allowed');

        $this->assertStringContainsString('allowed content', $result);
    }

    #[Test]
    public function symlinkInSubdirectoryIsChecked(): void
    {
        // Create a subdirectory
        $subDir = $this->viewsDir . '/subdir';
        mkdir($subDir, 0o750, true);

        // Create a target file outside the views directory
        $targetFile = $this->tempDir . '/secret.txt';
        file_put_contents($targetFile, 'secret content');

        // Create a symlink inside the subdirectory
        $symlinkPath = $subDir . '/secret.php';
        symlink($targetFile, $symlinkPath);

        // Try to render the symlinked view
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/recently created symlink/');

        $this->view->render('subdir.secret');
    }

    #[Test]
    public function normalViewFileIsAllowed(): void
    {
        // Create a normal view file
        $viewFile = $this->viewsDir . '/normal.php';
        file_put_contents($viewFile, 'normal content');

        // Try to render the normal view - should succeed
        $result = $this->view->render('normal');

        $this->assertStringContainsString('normal content', $result);
    }

    #[Test]
    public function symlinkErrorMessageIncludesPath(): void
    {
        // Create a target file
        $targetFile = $this->tempDir . '/secret.txt';
        file_put_contents($targetFile, 'secret content');

        // Create a symlink
        $symlinkPath = $this->viewsDir . '/secret.php';
        symlink($targetFile, $symlinkPath);

        try {
            $this->view->render('secret');
            $this->fail('Expected RuntimeException to be thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($symlinkPath, $e->getMessage());
        }
    }

    #[Test]
    public function symlinkErrorMessageIncludesAgeThreshold(): void
    {
        // Create a target file
        $targetFile = $this->tempDir . '/secret.txt';
        file_put_contents($targetFile, 'secret content');

        // Create a symlink
        $symlinkPath = $this->viewsDir . '/secret.php';
        symlink($targetFile, $symlinkPath);

        try {
            $this->view->render('secret');
            $this->fail('Expected RuntimeException to be thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('300 seconds', $e->getMessage());
        }
    }

    private function rmTree(string $path): void
    {
        $entries = @scandir($path) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->rmTree($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
