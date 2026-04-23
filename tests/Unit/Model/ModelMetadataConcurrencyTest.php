<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Model;

use Fiber;
use Fw\Model\Model;
use Fw\Model\ModelMetadata;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Item #15: Model::metadata() carried a spin-wait plus `metadataInitializing`
 * bookkeeping because "two Fibers could create duplicate metadata objects".
 * But metadata construction is a pure function of static class properties
 * (table, fillable, casts, ...). Two racing Fibers always produce
 * structurally identical objects; last write wins on the cache; no
 * correctness problem exists. The spin-wait with `maxSpins = 1000` was
 * extra complexity that could itself break (Fiber::suspend() with nothing
 * to resume it, or a force-proceed that ignored the lock on timeout).
 *
 * These tests lock in:
 *  - Concurrent Fiber resolution returns equivalent metadata.
 *  - The spin-wait / initializing-flag machinery is gone.
 *  - Repeat calls are still cached (no perf regression).
 */
final class ModelMetadataConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Model::clearMetadataCache();
    }

    #[Test]
    public function concurrentFibersResolveEquivalentMetadata(): void
    {
        $results = [];
        $fibers = [];

        for ($i = 0; $i < 5; $i++) {
            $fibers[$i] = new Fiber(static function () use (&$results, $i): void {
                // Fiber::suspend() once to interleave with siblings, then
                // proceed to request metadata.
                Fiber::suspend();
                $results[$i] = ModelMetadataTestSubject::exposedMetadata();
            });
            $fibers[$i]->start();
        }
        foreach ($fibers as $fiber) {
            $fiber->resume();
        }

        $this->assertCount(5, $results);
        $first = $results[0];
        $this->assertInstanceOf(ModelMetadata::class, $first);
        foreach ($results as $r) {
            $this->assertSame($first->class, $r->class);
            $this->assertSame($first->table, $r->table);
            $this->assertSame($first->fillable, $r->fillable);
        }
    }

    #[Test]
    public function metadataMethodNoLongerUsesSpinWait(): void
    {
        $method = new ReflectionMethod(Model::class, 'metadata');
        $body = implode('', array_slice(
            file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $this->assertStringNotContainsString(
            'metadataInitializing',
            $body,
            'metadata() should not coordinate via an initializing flag — construction is pure and idempotent.'
        );
        $this->assertStringNotContainsString(
            'maxSpins',
            $body,
            'metadata() should not contain a spin-wait loop — the operation is pure and cannot deadlock.'
        );
    }

    #[Test]
    public function initializingPropertyIsGone(): void
    {
        $rc = new ReflectionClass(Model::class);
        $this->assertFalse(
            $rc->hasProperty('metadataInitializing'),
            'The metadataInitializing coordination map is no longer needed; remove it so stale state cannot leak.'
        );
    }

    #[Test]
    public function repeatCallsHitCache(): void
    {
        $first = ModelMetadataTestSubject::exposedMetadata();
        $second = ModelMetadataTestSubject::exposedMetadata();
        $this->assertSame($first, $second, 'metadata() must still memoise results per class.');
    }
}

final class ModelMetadataTestSubject extends Model
{
    protected static ?string $table = 'metadata_test_subjects';
    protected static array $fillable = ['alpha', 'beta'];
    protected static array $casts = ['beta' => 'int'];

    public static function exposedMetadata(): ModelMetadata
    {
        return static::metadata();
    }
}
