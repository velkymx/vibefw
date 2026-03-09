<?php

declare(strict_types=1);

namespace Fw\Console\Commands;

use Fw\Console\Command;

/**
 * SecurityCheckCommand performs a basic audit of the scaffolded application for common security issues.
 */
final class SecurityCheckCommand extends Command
{
    protected string $name = 'security:check';

    protected string $description = 'Perform a basic security audit of the application';

    public function handle(): int
    {
        $this->info('🔒 Running security audit...');
        $warnings = 0;

        // 1. Check .env permissions (if on linux/mac)
        if (DIRECTORY_SEPARATOR === '/') {
            $envPath = BASE_PATH . '/.env';
            if (!file_exists($envPath)) {
                $this->warning('.env file not found — skipping permissions check.');
            } else {
                $perms = substr(sprintf('%o', fileperms($envPath)), -3);
                if ($perms !== '600' && $perms !== '640' && $perms !== '400') {
                    $this->warning(".env file has loose permissions ($perms). Recommend 600 or 640.");
                    $warnings++;
                }
            }
        }

        // 2. Check APP_DEBUG in production
        $appEnv = getenv('APP_ENV') ?: 'local';
        $appDebug = getenv('APP_DEBUG') ?: 'false';
        if ($appEnv === 'production' && $appDebug === 'true') {
            $this->error("SECURITY RISK: APP_DEBUG is 'true' in a production environment!");
            $warnings++;
        }

        // 3. Check for exposed sensitive files in public/
        $publicFiles = ['config.php', '.env', 'composer.json', 'package.json'];
        foreach ($publicFiles as $file) {
            if (file_exists(BASE_PATH . '/public/' . $file)) {
                $this->error("SECURITY RISK: Sensitive file '$file' found in public directory!");
                $warnings++;
            }
        }

        // 4. Check for hardcoded credentials in config (simple check)
        $configFiles = glob(BASE_PATH . '/config/*.php') ?: [];
        foreach ($configFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (preg_match('/[\'"](password|secret|key)[\'"]\s*=>\s*[\'"](?!env\()[^\'"]+[\'"]/', $content)) {
                $this->warning("Possible hardcoded credential found in " . basename($file));
                $warnings++;
            }
        }

        if ($warnings === 0) {
            $this->success('No immediate security risks found.');
            return 0;
        }

        $this->newLine();
        $this->warning("Audit complete with $warnings warning(s)/error(s).");
        return 1;
    }
}
