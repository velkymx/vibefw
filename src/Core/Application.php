<?php

declare(strict_types=1);

namespace Fw\Core;

use Fw\Async\EventLoop;
use Fw\Bus\CommandBus;
use Fw\Bus\QueryBus;
use Fw\Cache\Cache;
use Fw\Cache\CacheInterface;
use Fw\Cache\MemoryCache;
use Fw\Database\Connection;
use Fw\Events\EventDispatcher;
use Fw\Log\Logger;
use Fw\Security\Csrf;

/**
 * Main application class.
 */
final class Application
{
    public const string VERSION = '3.0.0';

    private static ?self $instance = null;

    public private(set) Logger $log;

    public private(set) ?Connection $db = null;

    public private(set) View $view;

    public private(set) Router $router;

    public private(set) Request $request;

    public private(set) Response $response;

    public private(set) Csrf $csrf;

    public private(set) EventDispatcher $events;

    public private(set) CommandBus $commands;

    public private(set) QueryBus $queries;

    private Container $container;

    // Extracted subsystems
    private Env $env;

    private Config $configRepository;

    private ProviderRegistry $providers;

    private HttpKernel $kernel;

    private ErrorHandler $errorHandler;

    private function __construct()
    {
        // 0. Set instance immediately to prevent recursive constructor calls
        self::$instance = $this;

        // 1. Initialize container first
        $this->container = Container::getInstance();

        // 2. Initialize environment and config
        $this->env = Env::getInstance();
        $this->env->loadVars(BASE_PATH . '/.env');
        $this->configRepository = new Config(BASE_PATH);
        $this->configRepository->load();

        // 3. Initialize fundamental objects
        $this->log = new Logger();
        $this->events = new EventDispatcher($this->container->resolver());
        $this->request = new Request();
        $this->response = new Response();
        $this->router = new Router();
        $this->csrf = new Csrf(
            fn () => $this->initSession(),
            (string) ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: ''),
        );
        $this->commands = new CommandBus($this->container->resolver());
        $this->queries = new QueryBus($this->container->resolver());

        // 4. Initialize Provider Registry
        $this->providers = new ProviderRegistry($this, $this->container, $this->log);
        $this->registerCoreProviders();
        $this->providers->loadFrom(BASE_PATH . '/config/providers.php');

        // 5. Bind core singletons manually so they are available before full boot
        $this->registerCoreSingletons();

        // 6. Register instances in container
        $this->registerContainerInstances();

        // 7. Boot providers (this calls register() then boot() on all added providers)
        $this->providers->boot();

        // 8. Initialize View (needs Cache from providers)
        $cache = $this->container->has(CacheInterface::class)
            ? $this->container->get(CacheInterface::class)
            : new Cache(new MemoryCache());

        $this->view = new View(
            BASE_PATH . '/app/Views',
            $cache,
            $this->router,
            $this->csrf
        );

        // 9. Re-register components
        $this->registerContainerInstances();

        // 10. Subsystems
        $this->initializeDatabase();
        $this->errorHandler = new ErrorHandler($this->response, $this->log, $this->configRepository);
        $this->kernel = new HttpKernel($this, $this->container, $this->router, $this->events, $this->errorHandler, $this->configRepository);

        // 11. Re-register now that View/Connection/HttpKernel exist so
        // ApplicationBooted listeners can resolve them from the container.
        $this->registerContainerInstances();

        // 12. Done
        $this->events->dispatch(new ApplicationBooted($this));
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Replace the boot-time Request placeholder with the live per-request
     * object. HttpKernel calls this once at the start of every handle()
     * invocation so middleware reading $app->request, and any service
     * resolving Request::class from the container, observe the active
     * request — never the stale boot snapshot.
     */
    public function bindRequest(Request $request): void
    {
        $this->request = $request;
        $this->container->instance(Request::class, $request);
    }

    public function initSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            // Session already active — nothing to do.
            return;
        }

        if (headers_sent($file, $line)) {
            // Cannot start session: headers already sent. Log to aid debugging
            // (CSRF tokens, flash data, and auth all silently fail without a session).
            error_log(sprintf(
                'Cannot start session: headers already sent in %s on line %d',
                $file,
                $line
            ));
            return;
        }

        $secure = $this->resolveSecureCookieSetting();
        $sameSite = $this->resolveSameSiteSetting();
        $domain = $this->configRepository->get('app.session_domain', '');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
        session_start();
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->configRepository->get($key, $default);
    }

    public function getConfig(): Config
    {
        return $this->configRepository;
    }

    public function getKernel(): HttpKernel
    {
        return $this->kernel;
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    private function registerCoreSingletons(): void
    {
        // Provide a default MemoryCache if CacheServiceProvider hasn't booted yet
        if (!$this->container->has(CacheInterface::class)) {
            $this->container->singleton(CacheInterface::class, fn () => new Cache(new MemoryCache()), true);
        }
    }

    private function registerCoreProviders(): void
    {
        // We use add() here to add them to the registry. boot() will then trigger their lifecycle.
        $this->providers->add(\Fw\Providers\EventServiceProvider::class);
        $this->providers->add(\Fw\Providers\BusServiceProvider::class);
        $this->providers->add(\Fw\Providers\MiddlewareServiceProvider::class);
        $this->providers->add(\Fw\Providers\DatabaseServiceProvider::class);
        $this->providers->add(\Fw\Providers\CacheServiceProvider::class);
        $this->providers->add(\Fw\Providers\QueueServiceProvider::class);
    }

    private function registerContainerInstances(): void
    {
        $this->container->instance(Env::class, $this->env, true);
        $this->container->instance(self::class, $this, true);
        $this->container->instance(Config::class, $this->configRepository, true);

        // Safety checks for typed properties
        if (isset($this->request)) {
            $this->container->instance(Request::class, $this->request);
        }
        if (isset($this->response)) {
            $this->container->instance(Response::class, $this->response);
        }
        if (isset($this->router)) {
            $this->container->instance(Router::class, $this->router);
        }
        if (isset($this->csrf)) {
            $this->container->instance(Csrf::class, $this->csrf);
        }
        if (isset($this->events)) {
            $this->container->instance(EventDispatcher::class, $this->events);
        }
        if (isset($this->commands)) {
            $this->container->instance(CommandBus::class, $this->commands);
        }
        if (isset($this->queries)) {
            $this->container->instance(QueryBus::class, $this->queries);
        }
        if (isset($this->log)) {
            $this->container->instance(Logger::class, $this->log, true);
        }
        if (isset($this->view)) {
            $this->container->instance(View::class, $this->view, true);
        }
        if (isset($this->kernel)) {
            $this->container->instance(HttpKernel::class, $this->kernel, true);
        }
        if ($this->db instanceof Connection) {
            $this->container->instance(Connection::class, $this->db, true);
        }

        $this->container->singleton(EventLoop::class, fn () => new EventLoop(), true);
    }

    private function initializeDatabase(): void
    {
        if (!$this->configRepository->get('database.enabled', false)) {
            return;
        }
        $this->container->singleton(Connection::class, function () {
            return Connection::createFresh($this->configRepository->section('database'));
        }, false);
        $this->db = $this->container->get(Connection::class);
    }

    private function resolveSecureCookieSetting(): bool
    {
        $configSecure = $this->configRepository->get('app.secure_cookies', true);
        $isProduction = $this->configRepository->get('app.env', 'production') === 'production';
        return $isProduction ?: (bool) $configSecure;
    }

    /**
     * Resolve the SameSite cookie attribute for session cookies.
     *
     * Default is 'Strict' (most secure). Use 'Lax' when cross-site POST flows
     * are required (e.g. OAuth callbacks, payment provider redirects).
     * 'None' requires Secure=true and is only appropriate for explicit cross-origin sharing.
     *
     * Configure via SESSION_SAME_SITE env var or app.session_same_site config key.
     */
    /** @return 'Strict'|'Lax'|'None' */
    private function resolveSameSiteSetting(): string
    {
        $value = $this->configRepository->get('app.session_same_site', 'Strict');
        return match (true) {
            in_array($value, ['Lax', 'lax'], true) => 'Lax',
            in_array($value, ['None', 'none'], true) => 'None',
            default => 'Strict',
        };
    }
}
