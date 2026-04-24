<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Core;

use Fw\Core\Env;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnvPrecedenceTest extends TestCase
{
    private string $testEnvFile;

    protected function setUp(): void
    {
        parent::setUp();
        Env::clear();
        $this->testEnvFile = sys_get_temp_dir() . '/fw_env_precedence_' . uniqid() . '.env';
    }

    protected function tearDown(): void
    {
        Env::clear();
        if (file_exists($this->testEnvFile)) {
            unlink($this->testEnvFile);
        }
        parent::tearDown();
    }

    #[Test]
    public function existingProcessEnvWinsWhenLoadingDotEnvFile(): void
    {
        $key = 'FW_ENV_PRECEDENCE_LOAD';
        $this->withProcessEnv($key, 'host-value', function (string $key): void {
            file_put_contents($this->testEnvFile, "{$key}=file-value\n");

            Env::load($this->testEnvFile);

            $this->assertSame('host-value', Env::string($key));
        });
    }

    #[Test]
    public function processEnvStillWinsAfterDotEnvValueWasCached(): void
    {
        $key = 'FW_ENV_PRECEDENCE_GET';
        file_put_contents($this->testEnvFile, "{$key}=file-value\n");

        Env::load($this->testEnvFile);
        $this->assertSame('file-value', Env::string($key));

        $this->withProcessEnv($key, 'host-value', function (string $key): void {
            $this->assertSame(
                'host-value',
                Env::string($key),
                'getVar() must prefer live process env over the internal .env cache.',
            );
        });
    }

    /**
     * @param callable(string): void $callback
     */
    private function withProcessEnv(string $key, string $value, callable $callback): void
    {
        $hadEnvArrayKey = array_key_exists($key, $_ENV);
        $previousEnvArrayValue = $_ENV[$key] ?? null;
        $previousGetenvValue = getenv($key);

        $_ENV[$key] = $value;
        putenv("{$key}={$value}");

        try {
            $callback($key);
        } finally {
            if ($hadEnvArrayKey) {
                $_ENV[$key] = $previousEnvArrayValue;
            } else {
                unset($_ENV[$key]);
            }

            if ($previousGetenvValue === false) {
                putenv($key);
            } else {
                putenv("{$key}={$previousGetenvValue}");
            }
        }
    }
}
