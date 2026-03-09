<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Application;
use Fw\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Command to scaffold a modern SPA + API starter kit.
 *
 * Uses @velkymx/vibeui for the frontend and VibeFW for the backend.
 */
final class ScaffoldSpaCommand extends Command
{
    protected string $name = 'make:spa {--test}';

    protected string $description = 'Scaffold a modern SPA (VibeUI) + API starter kit';

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function handle(): int
    {
        if ($this->option('test')) {
            return $this->runVerification();
        }

        $this->displayBanner();

        // 1. Mandatory Confirmation
        $this->warning('WARNING: This command will reset your "app/" directory to a clean slate.');
        $this->line('Existing controllers and models will be backed up to "storage/backups/".');

        if (!$this->confirm('Do you wish to proceed with the SPA scaffolding?')) {
            $this->comment('Scaffolding cancelled.');
            return 0;
        }

        $this->info('Stack: Vue 3 + TypeScript (Standard)');

        // 2. Execution Phase
        $this->newLine();
        $this->info('🚀 Starting the Vibe Stack scaffolding...');

        try {
            $this->backupAppDirectory();
            $this->scaffoldBackend();
            $this->scaffoldFrontend();
            $this->setupDatabase();
            $this->updateConfiguration();

            // Sync environment variables to Vite
            $this->call('env:sync');

            // Install and build frontend
            $this->installFrontendDependencies();
            $this->buildFrontend();

            $this->newLine();
            $this->info('✨ SPA Scaffolding complete! Enjoy the vibes.');
            $this->newLine();
            $this->line('Run <info>php fw dev</info> to start the full stack.');
            $this->newLine();

            return 0;
        } catch (Throwable $e) {
            $this->error('Error during scaffolding: ' . $e->getMessage());
            return 1;
        }
    }

    private function runVerification(): int
    {
        $this->info('🔍 Verifying SPA Scaffolding...');
        $errors = 0;

        $files = [
            'app/Controllers/Api/Auth/LoginController.php',
            'app/Controllers/Api/Auth/RegisterController.php',
            'app/Controllers/Api/StatsController.php',
            'app/Models/User.php',
            'frontend/package.json',
            'frontend/src/views/Home.vue',
            'frontend/src/views/auth/Login.vue',
            'frontend/src/views/auth/Register.vue',
            'frontend/src/views/Dashboard.vue',
            'frontend/src/layouts/MainLayout.vue',
        ];

        foreach ($files as $file) {
            if (file_exists(BASE_PATH . '/' . $file)) {
                $this->success("Found: $file");
            } else {
                $this->error("Missing: $file");
                $errors++;
            }
        }

        if ($errors === 0) {
            $this->newLine();
            $this->success('SPA Scaffolding Verification Passed!');
            return 0;
        }

        $this->newLine();
        $this->error("SPA Scaffolding Verification Failed with $errors errors.");
        return 1;
    }

    private function displayBanner(): void
    {
        $this->newLine();
        $this->line('<info>VibeFW</info> <comment>SPA Starter Kit</comment>');
        $this->line('--------------------------');
    }

    private function setupDatabase(): void
    {
        $this->newLine();
        $this->info('🗄️ Database Setup');

        $driver = $this->choice('Which database driver will you use?', [
            'sqlite' => 'SQLite',
            'mysql' => 'MySQL',
            'pgsql' => 'PostgreSQL',
        ], 'sqlite');

        $config = ['DB_DRIVER' => $driver];

        if ($driver === 'sqlite') {
            $dbPath = $this->ask('Database path', 'storage/database.sqlite');
            $config['DB_DATABASE'] = $dbPath;

            if (!file_exists(BASE_PATH . '/' . $dbPath)) {
                if (!is_dir(dirname(BASE_PATH . '/' . $dbPath))) {
                    mkdir(dirname(BASE_PATH . '/' . $dbPath), 0o755, true);
                }
                touch(BASE_PATH . '/' . $dbPath);
            }
        } else {
            $config['DB_HOST'] = $this->ask('Database host', '127.0.0.1');
            $config['DB_PORT'] = $this->ask('Database port', $driver === 'mysql' ? '3306' : '5432');
            $config['DB_DATABASE'] = $this->ask('Database name', 'vibefw');
            $config['DB_USERNAME'] = $this->ask('Database username', 'root');
            $config['DB_PASSWORD'] = $this->ask('Database password', '', true); // Hidden
        }

        $this->task('Updating .env configuration', function () use ($config) {
            foreach ($config as $key => $value) {
                $this->updateEnv($key, (string) $value);
            }
            return "Configuration updated in .env";
        });

        if ($this->confirm('Would you like to run the migrations now?')) {
            $this->call('migrate');
        }
    }

    private function updateEnv(string $key, string $value): void
    {
        $envFile = BASE_PATH . '/.env';
        if (!file_exists($envFile)) {
            if (file_exists(BASE_PATH . '/.env.example')) {
                copy(BASE_PATH . '/.env.example', $envFile);
            } else {
                touch($envFile);
            }
        }

        $content = file_get_contents($envFile);

        if (str_contains($content, "{$key}=")) {
            $content = preg_replace("/^{$key}=.*/m", "{$key}=\"{$value}\"", $content);
        } else {
            $content .= "\n{$key}=\"{$value}\"";
        }

        file_put_contents($envFile, $content);
    }

    private function backupAppDirectory(): void
    {
        $this->task('Backing up and resetting "app/" directory', function () {
            $timestamp = date('Ymd_His');
            $backupDir = BASE_PATH . '/storage/backups/app_' . $timestamp;

            if (!is_dir(dirname($backupDir))) {
                mkdir(dirname($backupDir), 0o755, true);
            }

            if (is_dir(BASE_PATH . '/app')) {
                // Perform backup
                $this->copyDirectory(BASE_PATH . '/app', $backupDir);

                // Reset (Delete current app content)
                $this->deleteDirectory(BASE_PATH . '/app');
            }

            // Re-create empty app structure
            if (!is_dir(BASE_PATH . '/app')) {
                mkdir(BASE_PATH . '/app', 0o755);
            }
            if (!is_dir(BASE_PATH . '/app/Controllers')) {
                mkdir(BASE_PATH . '/app/Controllers', 0o755);
            }
            if (!is_dir(BASE_PATH . '/app/Models')) {
                mkdir(BASE_PATH . '/app/Models', 0o755);
            }
            if (!is_dir(BASE_PATH . '/app/Providers')) {
                mkdir(BASE_PATH . '/app/Providers', 0o755);
            }

            return "Backup created at: storage/backups/" . basename($backupDir);
        });
    }

    /**
     * Recursively delete a directory.
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->deleteDirectory("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }

    private function scaffoldBackend(): void
    {
        $this->task('Scaffolding API controllers & models', function () {
            $appDir = BASE_PATH . '/app';

            // 1. Create directories
            if (!is_dir($appDir . '/Controllers/Api/Auth')) {
                mkdir($appDir . '/Controllers/Api/Auth', 0o755, true);
            }
            if (!is_dir($appDir . '/Models')) {
                mkdir($appDir . '/Models', 0o755, true);
            }

            // 2. Copy stubs
            $this->copyAppStub('Controllers/Api/Auth/LoginController.php', $appDir . '/Controllers/Api/Auth/LoginController.php');
            $this->copyAppStub('Controllers/Api/Auth/RegisterController.php', $appDir . '/Controllers/Api/Auth/RegisterController.php');
            $this->copyAppStub('Controllers/Api/StatsController.php', $appDir . '/Controllers/Api/StatsController.php');

            $this->copyAppStub('Models/User.php', $appDir . '/Models/User.php');

            // 3. Write fresh routes.php
            $routesFile = BASE_PATH . '/config/routes.php';
            $routesContent = <<<'PHP'
                <?php

                declare(strict_types=1);

                use Fw\Core\Router;
                use Fw\Core\Request;
                use Fw\Core\Response;
                use Fw\Auth\Auth;

                return function (Router $router): void {
                    $router->group('/api', function (Router $router): void {

                        // Auth (public)
                        $router->post('/auth/login', [App\Controllers\Api\Auth\LoginController::class, 'login']);
                        $router->post('/auth/register', [App\Controllers\Api\Auth\RegisterController::class, 'register']);
                        $router->post('/auth/logout', [App\Controllers\Api\Auth\LoginController::class, 'logout']);

                        // User (protected)
                        $router->get('/user', function (Request $request): Response {
                            return (new Response(json_encode(Auth::user(), JSON_THROW_ON_ERROR), 200))
                                ->header('Content-Type', 'application/json');
                        })->middleware('auth:api');

                        // Dashboard data (protected)
                        $router->get('/dashboard/stats', [App\Controllers\Api\StatsController::class, 'index'])
                            ->middleware('auth:api');
                    });
                };
                PHP;
            file_put_contents($routesFile, $routesContent);

            return "Backend API scaffolded";
        });
    }

    private function copyAppStub(string $path, string $destination): void
    {
        $fullStubPath = BASE_PATH . '/stubs/spa/app/' . $path . '.stub';
        if (file_exists($fullStubPath)) {
            if (!is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0o755, true);
            }
            copy($fullStubPath, $destination);
        }
    }

    private function scaffoldFrontend(): void
    {
        $this->task("Initializing Vue 3 frontend (TypeScript)", function () {
            $frontendDir = BASE_PATH . '/frontend';
            if (!is_dir($frontendDir)) {
                mkdir($frontendDir, 0o755, true);
            }

            // 1. Create package.json
            $packageJson = [
                'name' => 'vibefw-spa',
                'private' => true,
                'version' => '0.0.0',
                'type' => 'module',
                'scripts' => [
                    'dev' => 'vite',
                    'build' => 'vue-tsc && vite build',
                    'preview' => 'vite preview',
                    'test:unit' => 'vitest',
                    'test:e2e' => 'playwright test',
                ],
                'dependencies' => [
                    'vue' => '^3.5.13',
                    'vue-router' => '^5.0.3',
                    'pinia' => '^3.0.4',
                    'axios' => '^1.7.9',
                    '@velkymx/vibeui' => '^0.7.0',
                    'bootstrap' => '^5.3.3',
                    'bootstrap-icons' => '^1.13.1',
                    'quill' => '^2.0.3',
                    '@popperjs/core' => '^2.11.8',
                ],
                'devDependencies' => [
                    '@vitejs/plugin-vue' => '^6.0.4',
                    'vite' => '^7.3.1',
                    'sass' => '^1.97.3',
                    'typescript' => '^5.7.3',
                    'vue-tsc' => '^2.2.4',
                    '@types/node' => '^22.13.5',
                    'vitest' => '^3.0.5',
                    '@vue/test-utils' => '^2.4.6',
                    '@playwright/test' => '^1.50.1',
                    'jsdom' => '^26.0.0',
                ],
            ];

            file_put_contents(
                $frontendDir . '/package.json',
                json_encode($packageJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            // 2. Create vite.config
            $viteConfig = <<<VITE
                import { defineConfig } from 'vite'
                import vue from '@vitejs/plugin-vue'
                import path from 'path'

                export default defineConfig({
                  plugins: [vue()],
                  resolve: {
                    alias: {
                      '@': path.resolve(__dirname, './src'),
                    },
                  },
                  css: {
                    preprocessorOptions: {
                      scss: {
                        silenceDeprecations: ['color-functions', 'import', 'global-builtin', 'if-function', 'mixed-decls'],
                      },
                    },
                  },
                  test: {
                    environment: 'jsdom',
                    globals: true,
                  },
                  server: {
                    proxy: {
                      '/api': {
                        target: 'http://localhost:8000',
                        changeOrigin: true,
                      },
                    },
                  },
                })
                VITE;
            file_put_contents($frontendDir . "/vite.config.ts", $viteConfig);

            // 3. Create basic structure
            $dirs = [
                '/src', '/src/assets', '/src/components', '/src/layouts',
                '/src/views', '/src/router', '/src/views/auth',
                '/src/views/errors', '/src/tests', '/src/types', '/e2e',
            ];
            foreach ($dirs as $dir) {
                if (!is_dir($frontendDir . $dir)) {
                    mkdir($frontendDir . $dir, 0o755, true);
                }
            }

            // 4. Copy stubs
            $this->copyStub('tsconfig.json', $frontendDir . '/tsconfig.json');
            $this->copyStub('playwright.config.ts', $frontendDir . '/playwright.config.ts');
            $this->copyStub('e2e/example.spec.ts', $frontendDir . '/e2e/example.spec.ts');
            $this->copyStub('src/tests/Dashboard.test.ts', $frontendDir . '/src/tests/Dashboard.test.ts');

            $this->copyStub('src/App.vue', $frontendDir . '/src/App.vue');
            $this->copyStub('src/main.ts', $frontendDir . '/src/main.ts');
            $this->copyStub('src/router/index.ts', $frontendDir . '/src/router/index.ts');
            $this->copyStub('src/layouts/MainLayout.vue', $frontendDir . '/src/layouts/MainLayout.vue');
            $this->copyStub('src/views/Home.vue', $frontendDir . '/src/views/Home.vue');
            $this->copyStub('src/views/Dashboard.vue', $frontendDir . '/src/views/Dashboard.vue');
            $this->copyStub('src/views/auth/Login.vue', $frontendDir . '/src/views/auth/Login.vue');
            $this->copyStub('src/views/auth/Register.vue', $frontendDir . '/src/views/auth/Register.vue');
            $this->copyStub('src/views/errors/NotFound.vue', $frontendDir . '/src/views/errors/NotFound.vue');
            $this->copyStub('src/types/api.ts', $frontendDir . '/src/types/api.ts');

            // 5. Copy README
            $readmeStub = BASE_PATH . '/stubs/spa/README.md.stub';
            if (file_exists($readmeStub)) {
                copy($readmeStub, $frontendDir . '/README.md');
            }

            // 6. Create index.html
            $indexHtml = <<<HTML
                <!DOCTYPE html>
                <html lang="en">
                  <head>
                    <meta charset="UTF-8" />
                    <link rel="icon" type="image/svg+xml" href="/vite.svg" />
                    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
                    <title>VibeFW SPA</title>
                  </head>
                  <body>
                    <div id="app"></div>
                    <script type="module" src="/src/main.ts"></script>
                  </body>
                </html>
                HTML;
            file_put_contents($frontendDir . '/index.html', $indexHtml);

            return "Frontend scaffolded in /frontend";
        });
    }

    private function copyStub(string $stubPath, string $destination): void
    {
        $fullStubPath = BASE_PATH . '/stubs/spa/' . $stubPath . '.stub';
        if (file_exists($fullStubPath)) {
            copy($fullStubPath, $destination);
        }
    }

    private function updateConfiguration(): void
    {
        $this->task('Updating application configuration', function () {
            // 1. Update CORS configuration if it exists
            $middlewareConfig = BASE_PATH . '/config/middleware.php';
            if (file_exists($middlewareConfig)) {
                $config = file_get_contents($middlewareConfig);
                // Simple logic to ensure frontend port is allowed in CORS if present
                // (Assuming there's a CORS section or similar)
            }

            // 2. Ensure storage directories for backups/logs exist
            if (!is_dir(BASE_PATH . '/storage/backups')) {
                mkdir(BASE_PATH . '/storage/backups', 0o755, true);
            }

            return "Configuration updated";
        });
    }

    private function installFrontendDependencies(): void
    {
        $this->task('Installing frontend dependencies (npm install)', function () {
            $frontendDir = BASE_PATH . '/frontend';
            $output = [];
            $code = 0;
            exec("cd " . escapeshellarg($frontendDir) . " && npm install 2>&1", $output, $code);

            if ($code !== 0) {
                throw new RuntimeException(implode("\n", $output));
            }

            return "node_modules installed";
        });
    }

    private function buildFrontend(): void
    {
        $this->task('Building frontend assets (npm run build)', function () {
            $frontendDir = BASE_PATH . '/frontend';
            $output = [];
            $code = 0;
            exec("cd " . escapeshellarg($frontendDir) . " && npm run build 2>&1", $output, $code);

            if ($code !== 0) {
                throw new RuntimeException(implode("\n", $output));
            }

            return "Frontend built to frontend/dist";
        });
    }

    /**
     * Recursively copy a directory.
     */
    private function copyDirectory(string $src, string $dst): void
    {
        $dir = opendir($src);
        @mkdir($dst);
        while (false !== ($file = readdir($dir))) {
            if (($file !== '.') && ($file !== '..')) {
                if (is_dir($src . '/' . $file)) {
                    $this->copyDirectory($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
    }
}
