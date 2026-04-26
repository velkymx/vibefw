<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Model;

use Fw\Model\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * M6: updateOrCreate/firstOrCreate retry loop had no backoff.
 *
 * When a race condition caused a duplicate-key error, the retry loop
 * immediately re-tried without any delay, causing contending workers
 * to repeatedly clash. After fix, retries use exponential backoff
 * with jitter, and SQLSTATE is read from errorInfo[0] (not getCode())
 * which is the reliable source per the PDO spec.
 */
final class UpdateOrCreateRetryBackoffTest extends TestCase
{
    #[Test]
    public function updateOrCreateHasRetryBackoff(): void
    {
        $source = file_get_contents((new ReflectionClass(Model::class))->getFileName());
        $this->assertStringContainsString('usleep', $source, 'updateOrCreate retry loop should include backoff via usleep()');
        $this->assertStringContainsString('random_int', $source, 'updateOrCreate retry loop should include jitter via random_int()');
    }

    #[Test]
    public function updateOrCreateUsesErrorInfoZeroForSqlState(): void
    {
        $source = file_get_contents((new ReflectionClass(Model::class))->getFileName());
        $this->assertStringContainsString('errorInfo[0]', $source, 'updateOrCreate should check errorInfo[0] for SQLSTATE, not just getCode()');
    }

    #[Test]
    public function maxUpsertRetriesIsBounded(): void
    {
        $m = (new ReflectionClass(Model::class))->getConstant('MAX_UPSERT_RETRIES');
        $this->assertIsInt($m);
        $this->assertGreaterThan(0, $m);
        $this->assertLessThanOrEqual(10, $m, 'MAX_UPSERT_RETRIES should be bounded to prevent excessive retries');
    }

    #[Test]
    public function updateOrCreateMethodExists(): void
    {
        $this->assertTrue(method_exists(Model::class, 'updateOrCreate'));
    }

    #[Test]
    public function firstOrCreateMethodExists(): void
    {
        $this->assertTrue(method_exists(Model::class, 'firstOrCreate'));
    }
}
