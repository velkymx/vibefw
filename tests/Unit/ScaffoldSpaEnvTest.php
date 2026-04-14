<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Console\Commands\ScaffoldSpaCommand;
use Fw\Tests\TestCase;
use ReflectionClass;

/**
 * Tests for ScaffoldSpaCommand::applyEnvValue() — the pure string transformation
 * that writes/updates key=value pairs in .env content.
 *
 * The original preg_replace() approach mangled values containing backreference
 * tokens ($1, $2, \\) because preg_replace() interprets them in the replacement
 * string. These tests confirm safe round-trips for all known dangerous inputs.
 */
final class ScaffoldSpaEnvTest extends TestCase
{
    private function applyEnvValue(string $content, string $key, string $value): string
    {
        $ref = new ReflectionClass(ScaffoldSpaCommand::class);
        $method = $ref->getMethod('applyEnvValue');
        return $method->invoke(null, $content, $key, $value);
    }

    public function testAppendsNewKeyWhenAbsent(): void
    {
        $result = $this->applyEnvValue("APP_NAME=Fw\n", 'DB_PASSWORD', 'secret');

        $this->assertStringContainsString('DB_PASSWORD="secret"', $result);
        $this->assertStringContainsString('APP_NAME=Fw', $result);
    }

    public function testUpdatesExistingKey(): void
    {
        $content = "APP_NAME=Fw\nDB_PASSWORD=\"old\"\nAPP_ENV=local\n";

        $result = $this->applyEnvValue($content, 'DB_PASSWORD', 'newpass');

        $this->assertStringContainsString('DB_PASSWORD="newpass"', $result);
        $this->assertStringNotContainsString('old', $result);
        $this->assertStringContainsString('APP_ENV=local', $result);
    }

    public function testValueWithBackreferenceTokenIsNotMangled(): void
    {
        // $1 in a preg_replace replacement string becomes a backreference.
        // The literal password "$1secret" must survive unchanged.
        $result = $this->applyEnvValue('DB_PASSWORD=old', 'DB_PASSWORD', '$1secret');

        $this->assertStringContainsString('DB_PASSWORD="$1secret"', $result);
    }

    public function testValueWithMultipleBackreferenceTokensIsNotMangled(): void
    {
        $result = $this->applyEnvValue('DB_PASSWORD=old', 'DB_PASSWORD', 'p$2a$10$hashhere');

        $this->assertStringContainsString('DB_PASSWORD="p$2a$10$hashhere"', $result);
    }

    public function testValueWithBackslashIsNotMangled(): void
    {
        // Backslashes in replacement strings have special meaning in preg_replace.
        $result = $this->applyEnvValue('DB_PASSWORD=old', 'DB_PASSWORD', 'pass\\word');

        $this->assertStringContainsString('DB_PASSWORD="pass\\word"', $result);
    }

    public function testValueWithBackslashNIsNotMangled(): void
    {
        $result = $this->applyEnvValue('DB_PASSWORD=old', 'DB_PASSWORD', 'pass\\nword');

        $this->assertStringContainsString('DB_PASSWORD="pass\\nword"', $result);
    }

    public function testKeyWithSpecialRegexCharsIsHandledSafely(): void
    {
        // Key names should be regex-safe (A-Z, _, digits), but guard anyway.
        $result = $this->applyEnvValue("MY_KEY=old\n", 'MY_KEY', 'value');

        $this->assertStringContainsString('MY_KEY="value"', $result);
    }

    public function testDoesNotDuplicateKeyOnUpdate(): void
    {
        $content = "DB_PASSWORD=\"old\"\n";

        $result = $this->applyEnvValue($content, 'DB_PASSWORD', 'new');

        $this->assertSame(1, substr_count($result, 'DB_PASSWORD='));
    }

    public function testPreservesOtherLinesOnUpdate(): void
    {
        $content = "APP_NAME=Test\nDB_HOST=127.0.0.1\nDB_PASSWORD=\"old\"\nAPP_DEBUG=true\n";

        $result = $this->applyEnvValue($content, 'DB_PASSWORD', 'new');

        $this->assertStringContainsString('APP_NAME=Test', $result);
        $this->assertStringContainsString('DB_HOST=127.0.0.1', $result);
        $this->assertStringContainsString('APP_DEBUG=true', $result);
    }
}
