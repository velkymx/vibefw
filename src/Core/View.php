<?php

declare(strict_types=1);

namespace Fw\Core;

use Closure;
use DateTimeInterface;
use Fw\Cache\CacheInterface;
use Fw\Security\Csrf;
use Fw\Support\Arr;
use Fw\Support\DateTime;
use Fw\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class View
{
    /**
     * Reserved variable names that cannot be used in view data.
     * These are used by view helpers and internal rendering.
     */
    private const RESERVED_NAMES = [
        'e', 'url', 'csrf', 'section', 'endSection', 'yield',
        'strLimit', 'strSlug', 'strUpper', 'strLower', 'strTitle', 'strExcerpt',
        'formatDate', 'timeAgo', 'Str', 'DateTime', 'Arr',
        'path', 'data', 'this', 'cache', 'endCache',
        // Commonly injected framework state — shadowing these in view data
        // silently hides auth/session/validation context from templates.
        'auth', 'user', 'errors',
        // PHP superglobals
        '_GET', '_POST', '_SERVER', '_REQUEST', '_SESSION', '_COOKIE',
        '_FILES', '_ENV', 'GLOBALS', 'argc', 'argv',
    ];

    /**
     * Pattern for valid variable names in view data.
     * Prevents injection of internal/magic variable names.
     */
    private const VALID_VAR_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]*$/';

    private string $basePath;

    private ?string $layout = null;

    private array $sections = [];

    private ?string $currentSection = null;

    private array $shared = [];

    private CacheInterface $cache;

    private Router $router;

    private Csrf $csrf;

    private ?ViewCache $viewCache = null;

    /**
     * Pre-built helper closures (created once, reused).
     * @var array<string, Closure>
     */
    private array $helpers = [];

    // =========================================================================
    // View Caching
    // =========================================================================

    private ?string $currentCacheKey = null;

    private int $currentCacheTtl = 3600;

    public function __construct(
        string $basePath,
        CacheInterface $cache,
        Router $router,
        Csrf $csrf,
    ) {
        $this->basePath = rtrim($basePath, '/');
        $this->cache = $cache;
        $this->router = $router;
        $this->csrf = $csrf;
        $this->initHelpers();
        // Closure with null scope so $this is unbound inside included templates.
        // Variables are passed via the $vars array and extracted inside the closure
        // so templates have access to helpers and data without reaching View internals.
        $this->templateRenderer = Closure::bind(
            static function (string $path, array $vars): void {
                extract($vars, EXTR_SKIP);
                include $path;
            },
            null,
            null,
        );
    }

    /**
     * Enable view caching for rendered output.
     */
    public function enableCache(string $cachePath): self
    {
        $this->viewCache = new ViewCache($cachePath);
        return $this;
    }

    public function share(string $key, mixed $value): self
    {
        // Keep the key validated up-front so a reserved/malformed name
        // blows up at the share() call, not silently at render time.
        $this->validateViewDataKey($key);
        $this->shared[$key] = $value;
        return $this;
    }

    public function layout(string $name): self
    {
        $this->layout = $name;
        return $this;
    }

    public function render(string $view, array $data = []): string
    {
        $content = $this->renderView($view, $data);

        if ($this->layout !== null) {
            $layoutData = array_merge($data, ['content' => $content]);
            $content = $this->renderView("layouts/{$this->layout}", $layoutData);
            $this->layout = null;
        }

        $this->sections = [];

        return $content;
    }

    public function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function section(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }

    public function endSection(): void
    {
        if ($this->currentSection === null) {
            throw new RuntimeException('No section started');
        }

        $this->sections[$this->currentSection] = ob_get_clean() ?: '';
        $this->currentSection = null;
    }

    public function yield(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function include(string $view, array $data = []): string
    {
        return $this->renderView($view, $data);
    }

    public function exists(string $view): bool
    {
        return file_exists($this->resolvePath($view));
    }

    /**
     * Render a view with full-page caching.
     *
     * @param string $view View name
     * @param array $data View data
     * @param int $ttl Cache TTL in seconds (0 = forever)
     * @return string Rendered content
     */
    public function renderCached(string $view, array $data = [], int $ttl = 3600): string
    {
        if ($this->viewCache === null) {
            return $this->render($view, $data);
        }

        $key = ViewCache::makeKey($view, $data, $this->resolvePath($view));
        $cached = $this->viewCache->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $content = $this->render($view, $data);
        $this->viewCache->set($key, $content, $ttl);

        return $content;
    }

    /**
     * Invalidate a cached view.
     */
    public function invalidate(string $view, array $data = []): void
    {
        $this->viewCache?->forget(ViewCache::makeKey($view, $data));
    }

    /**
     * Invalidate a cached fragment.
     */
    public function invalidateFragment(string $key): void
    {
        $this->viewCache?->forget('fragment_' . $key);
    }

    /**
     * Clear all view cache.
     */
    public function clearCache(): void
    {
        $this->viewCache?->flush();
    }

    // =========================================================================
    // Streaming
    // =========================================================================

    /**
     * Stream a view directly to output (no buffering).
     *
     * For large pages, this reduces memory usage and improves Time-To-First-Byte.
     *
     * Usage:
     *   return new StreamedResponse(fn() => $view->stream('large-page', $data));
     */
    public function stream(string $view, array $data = []): void
    {
        $path = $this->resolvePath($view);

        if (!file_exists($path)) {
            throw new RuntimeException("View not found: $view");
        }

        $data = array_merge($this->shared, $data);
        $this->validateViewData($data);

        // Collect all template variables (same structure as renderView)
        $vars = [
            'e' => $this->helpers['e'],
            'url' => $this->helpers['url'],
            'csrf' => $this->helpers['csrf'],
            'old' => $this->helpers['old'],
            'section' => $this->helpers['section'],
            'endSection' => $this->helpers['endSection'],
            'yield' => $this->helpers['yield'],
            'strLimit' => $this->helpers['strLimit'],
            'strSlug' => $this->helpers['strSlug'],
            'strUpper' => $this->helpers['strUpper'],
            'strLower' => $this->helpers['strLower'],
            'strTitle' => $this->helpers['strTitle'],
            'strExcerpt' => $this->helpers['strExcerpt'],
            'formatDate' => $this->helpers['formatDate'],
            'timeAgo' => $this->helpers['timeAgo'],
            // No caching in stream mode (stubs that do nothing)
            'cache' => fn (string $key, int $ttl = 3600): bool => true,
            'endCache' => function (): void {},
            // Support classes
            'Str' => Str::class,
            'DateTime' => DateTime::class,
            'Arr' => Arr::class,
            // View data
            ...$data,
        ];

        // Direct output, no buffering — $this is unbound via renderTemplate()
        $this->renderTemplate($path, $vars);

        // Flush after each chunk for true streaming
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Create a streamed response for this view.
     */
    public function streamed(string $view, array $data = []): StreamedResponse
    {
        return new StreamedResponse(fn () => $this->stream($view, $data));
    }

    /**
     * Initialize helper closures once (performance optimization).
     */
    private function initHelpers(): void
    {
        // These closures are created once and reused for all renders
        $this->helpers = [
            'e' => fn (string $value): string => $this->escape($value),
            'url' => fn (string $name, array $params = []): string => $this->url($name, $params),
            'csrf' => fn (): string => $this->csrfField(),
            'old' => fn (string $key, mixed $default = null): mixed => self::getOldInput($key, $default),
            'section' => function (string $name): void {
                $this->section($name);
            },
            'endSection' => function (): void {
                $this->endSection();
            },
            'yield' => fn (string $name, string $default = ''): string => $this->yield($name, $default),
            'strLimit' => fn (string $value, int $limit = 100, string $end = '...') => Str::limit($value, $limit, $end),
            'strSlug' => fn (string $value) => Str::slug($value),
            'strUpper' => fn (string $value) => Str::upper($value),
            'strLower' => fn (string $value) => Str::lower($value),
            'strTitle' => fn (string $value) => Str::title($value),
            'strExcerpt' => fn (string $text, string $phrase = '', int $radius = 100) => Str::excerpt($text, $phrase, $radius),
            'formatDate' => fn (?DateTimeInterface $date, string $format = 'F j, Y') => $date?->format($format) ?? '',
            'timeAgo' => fn (?DateTimeInterface $date) => $date ? DateTime::wrap($date)->diffForHumans() : '',
        ];
    }

    private function renderView(string $view, array $data): string
    {
        $path = $this->resolvePath($view);

        if (!file_exists($path)) {
            throw new RuntimeException("View not found: $view");
        }

        $data = array_merge($this->shared, $data);
        $this->validateViewData($data);

        // Collect all template variables: helpers + support classes + data.
        // These are extracted inside the null-bound closure so templates
        // cannot access $this (View internals).
        $vars = [
            'e' => $this->helpers['e'],
            'url' => $this->helpers['url'],
            'csrf' => $this->helpers['csrf'],
            'old' => $this->helpers['old'],
            'section' => $this->helpers['section'],
            'endSection' => $this->helpers['endSection'],
            'yield' => $this->helpers['yield'],
            'strLimit' => $this->helpers['strLimit'],
            'strSlug' => $this->helpers['strSlug'],
            'strUpper' => $this->helpers['strUpper'],
            'strLower' => $this->helpers['strLower'],
            'strTitle' => $this->helpers['strTitle'],
            'strExcerpt' => $this->helpers['strExcerpt'],
            'formatDate' => $this->helpers['formatDate'],
            'timeAgo' => $this->helpers['timeAgo'],
            // Fragment caching helpers
            'cache' => fn (string $key, int $ttl = 3600): bool => $this->startCache($key, $ttl),
            'endCache' => function (): void {
                $this->endCache();
            },
            // Support classes
            'Str' => Str::class,
            'DateTime' => DateTime::class,
            'Arr' => Arr::class,
            // View data (EXTR_SKIP in the closure prevents overwriting helpers)
            ...$data,
        ];

        $obLevel = ob_get_level();
        ob_start();

        try {
            $this->renderTemplate($path, $vars);
            return ob_get_clean() ?: '';
        } catch (Throwable $ex) {
            // Clean all output buffers opened since we started (handles nested
            // fragment cache ob_start() calls that didn't reach endCache()).
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
            throw $ex;
        }
    }

    /**
     * Render a template file with $this unbound.
     *
     * The templateRenderer closure is bound to null scope, so $this
     * is not available inside the included template. All variables
     * (helpers + data) are passed via the $vars array and extracted
     * inside the closure.
     */
    private function renderTemplate(string $path, array $vars): void
    {
        ($this->templateRenderer)($path, $vars);
    }

    /**
     * Closure used to render templates with $this unbound.
     *
     * Created once in the constructor for performance.
     * @var Closure(string): void
     */
    private Closure $templateRenderer;

    /**
     * Retrieve old input from RequestContext (fiber-safe) or $_SESSION fallback.
     */
    private static function getOldInput(string $key, mixed $default = null): mixed
    {
        $ctx = RequestContext::current();
        if ($ctx !== null && $ctx->has('_old_input')) {
            $oldInput = $ctx->get('_old_input')->unwrapOr([]);
            if (is_array($oldInput) && array_key_exists($key, $oldInput)) {
                return $oldInput[$key];
            }
            return $default;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            return $_SESSION['_old_input'][$key] ?? $default;
        }

        return $default;
    }

    private function resolvePath(string $view): string
    {
        $view = str_replace('.', '/', $view);
        $path = "{$this->basePath}/{$view}.php";

        // Prevent directory traversal: resolve the real path and ensure it
        // stays within the views base directory.
        $realBase = realpath($this->basePath);
        $realPath = realpath($path);

        if ($realBase === false) {
            throw new RuntimeException("View base path does not exist: {$this->basePath}");
        }

        // If the file doesn't exist yet (e.g. exists() check before creation),
        // validate the directory component instead.
        if ($realPath === false) {
            $dir = realpath(dirname($path));
            if ($dir !== false && !str_starts_with($dir, $realBase)) {
                throw new RuntimeException("View path traversal detected: {$view}");
            }
            return $path;
        }

        // SECURITY: Check for recently created symlinks in the path BEFORE
        // checking path traversal. We check the original path (not the resolved
        // path) because realpath() follows symlinks, so we need to check the
        // path components for symlinks before they're resolved.
        $this->checkForRecentSymlinks($path);

        if (!str_starts_with($realPath, $realBase)) {
            throw new RuntimeException("View path traversal detected: {$view}");
        }

        return $realPath;
    }

    /**
     * Check if any component of the path is a symlink created recently.
     *
     * @throws RuntimeException If a symlink was created in the last 5 minutes
     */
    private function checkForRecentSymlinks(string $path): void
    {
        $now = time();
        $symlinkAgeThreshold = 300; // 5 minutes

        // Check each component of the path for symlinks
        $parts = explode('/', $path);
        $currentPath = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $currentPath .= '/' . $part;

            if (is_link($currentPath)) {
                $mtime = @filemtime($currentPath);
                if ($mtime !== false && ($now - $mtime) < $symlinkAgeThreshold) {
                    throw new RuntimeException(
                        "View path contains a recently created symlink: {$currentPath}. " .
                        "This may be a path traversal attempt. Symlinks must be at least " .
                        "{$symlinkAgeThreshold} seconds old."
                    );
                }
            }
        }
    }

    /**
     * Validate view data keys to prevent variable injection attacks.
     *
     * @throws InvalidArgumentException If any key is invalid
     */
    private function validateViewData(array $data): void
    {
        foreach (array_keys($data) as $key) {
            $this->validateViewDataKey((string) $key);
        }
    }

    private function validateViewDataKey(string $key): void
    {
        if (in_array($key, self::RESERVED_NAMES, true)) {
            throw new InvalidArgumentException(
                "View data contains reserved variable name: {$key}"
            );
        }
        if (!preg_match(self::VALID_VAR_PATTERN, $key)) {
            throw new InvalidArgumentException(
                "Invalid view variable name: {$key}. Must start with a letter and contain only alphanumeric characters and underscores."
            );
        }
    }

    private function url(string $name, array $params = []): string
    {
        return $this->router->url($name, $params);
    }

    private function csrfField(): string
    {
        $token = $this->csrf->getToken();
        return '<input type="hidden" name="' . Csrf::FIELD_NAME . '" value="' . $this->escape($token) . '">';
    }

    /**
     * Start a cached fragment in a view.
     *
     * Usage in view:
     *   <?php if ($cache('sidebar', 3600)): ?>
     *       <!-- expensive sidebar content -->
     *   <?php $endCache(); endif; ?>
     *
     * @return bool True if content should be rendered (cache miss), false if cached
     */
    private function startCache(string $key, int $ttl = 3600): bool
    {
        if ($this->viewCache === null) {
            return true; // No caching, always render
        }

        $cached = $this->viewCache->get('fragment_' . $key);

        if ($cached !== null) {
            echo $cached;
            return false; // Don't render, we echoed cached content
        }

        $this->currentCacheKey = $key;
        $this->currentCacheTtl = $ttl;
        ob_start();

        return true; // Render the content
    }

    /**
     * End a cached fragment and store it.
     */
    private function endCache(): void
    {
        if ($this->currentCacheKey === null || $this->viewCache === null) {
            return;
        }

        $content = ob_get_clean() ?: '';
        $this->viewCache->set('fragment_' . $this->currentCacheKey, $content, $this->currentCacheTtl);
        $this->currentCacheKey = null;
        echo $content;
    }
}
