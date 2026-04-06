<?php

declare(strict_types=1);

namespace Fw\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Verify Model lockdown: $fillable only, no $guarded, strict mode permanent.
 */
final class ModelLockdownTest extends TestCase
{
    /**
     * Properties and methods that must NOT exist on Model.
     *
     * @return array<string, array{string, string}>
     */
    public static function removedMembersProvider(): array
    {
        return [
            'property $guarded'         => ['property', 'guarded'],
            'property $strictFillable'  => ['property', 'strictFillable'],
            'property $globalStrictMode' => ['property', 'globalStrictMode'],
            'method enableStrictMode'   => ['method', 'enableStrictMode'],
            'method disableStrictMode'  => ['method', 'disableStrictMode'],
            'method resetStrictMode'    => ['method', 'resetStrictMode'],
            'method isStrictMode'       => ['method', 'isStrictMode'],
        ];
    }

    #[Test]
    #[DataProvider('removedMembersProvider')]
    public function modelDoesNotHaveRemovedMember(string $type, string $name): void
    {
        $ref = new ReflectionClass(\Fw\Model\Model::class);

        if ($type === 'property') {
            $this->assertFalse(
                $ref->hasProperty($name),
                "Model must not have removed property: \${$name}"
            );
        } else {
            $this->assertFalse(
                $ref->hasMethod($name),
                "Model must not have removed method: {$name}()"
            );
        }
    }

    #[Test]
    public function modelStillHasFillableProperty(): void
    {
        $ref = new ReflectionClass(\Fw\Model\Model::class);

        $this->assertTrue(
            $ref->hasProperty('fillable'),
            'Model must have $fillable property'
        );
    }

    #[Test]
    public function modelMetadataDoesNotHaveGuarded(): void
    {
        $ref = new ReflectionClass(\Fw\Model\ModelMetadata::class);

        $this->assertFalse(
            $ref->hasProperty('guarded'),
            'ModelMetadata must not have $guarded property'
        );
    }

    #[Test]
    public function modelMetadataIsFillableRequiresFillableArray(): void
    {
        $metadata = new \Fw\Model\ModelMetadata(
            class: \Fw\Model\Model::class,
            table: 'test',
            primaryKey: 'id',
            incrementing: true,
            keyType: 'int',
            timestamps: true,
            createdAtColumn: 'created_at',
            updatedAtColumn: 'updated_at',
            fillable: ['name', 'email'],
            casts: [],
        );

        $this->assertTrue($metadata->isFillable('name'));
        $this->assertTrue($metadata->isFillable('email'));
        $this->assertFalse($metadata->isFillable('id'));
        $this->assertFalse($metadata->isFillable('admin'));
    }

    #[Test]
    public function modelMetadataEmptyFillableRejectsAll(): void
    {
        $metadata = new \Fw\Model\ModelMetadata(
            class: \Fw\Model\Model::class,
            table: 'test',
            primaryKey: 'id',
            incrementing: true,
            keyType: 'int',
            timestamps: true,
            createdAtColumn: 'created_at',
            updatedAtColumn: 'updated_at',
            fillable: [],
            casts: [],
        );

        $this->assertFalse($metadata->isFillable('name'));
        $this->assertFalse($metadata->isFillable('anything'));
    }
}
