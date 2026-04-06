<?php

declare(strict_types=1);

namespace Fw\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verify that methods returning Result, Option, or important values
 * are annotated with #[\NoDiscard] so PHP 8.5 warns if the return
 * value is silently ignored.
 */
final class NoDiscardTest extends TestCase
{
    /**
     * @return array<string, array{class-string, string}>
     */
    public static function noDiscardMethodsProvider(): array
    {
        return [
            // Result methods
            'Result::map'      => [\Fw\Support\Result::class, 'map'],
            'Result::mapErr'   => [\Fw\Support\Result::class, 'mapErr'],
            'Result::andThen'  => [\Fw\Support\Result::class, 'andThen'],

            // Option methods
            'Option::map'    => [\Fw\Support\Option::class, 'map'],
            'Option::filter' => [\Fw\Support\Option::class, 'filter'],

            // Controller methods
            'Controller::dispatch' => [\Fw\Core\Controller::class, 'dispatch'],
            'Controller::query'    => [\Fw\Core\Controller::class, 'query'],

            // Model methods
            'Model::save'   => [\Fw\Model\Model::class, 'save'],
            'Model::find'   => [\Fw\Model\Model::class, 'find'],
            'Model::create' => [\Fw\Model\Model::class, 'create'],
            'Model::delete' => [\Fw\Model\Model::class, 'delete'],
        ];
    }

    #[Test]
    #[DataProvider('noDiscardMethodsProvider')]
    public function methodHasNoDiscardAttribute(string $class, string $method): void
    {
        $ref = new ReflectionMethod($class, $method);
        $attributes = $ref->getAttributes(\NoDiscard::class);

        $this->assertNotEmpty(
            $attributes,
            "{$class}::{$method}() must have #[\\NoDiscard] attribute"
        );
    }
}
