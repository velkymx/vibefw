<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Model;

use Fw\Model\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L4: Model lazyLoadReporter is documented in CLAUDE.md.
 *
 * Pre-fix: The $lazyLoadReporter property is public static and allows swapping
 * the reporter, but this is not documented in CLAUDE.md or the model patterns
 * section.
 *
 * Post-fix: Documented the feature in CLAUDE.md under Model Patterns section.
 */
final class ModelLazyLoadReporterDocumentationTest extends TestCase
{
    #[Test]
    public function lazyLoadReporterIsDocumentedInClaudeMd(): void
    {
        $claudeMd = file_get_contents('/Users/velkymx/Code/fw/CLAUDE.md');
        $this->assertStringContainsString(
            'Lazy Load Reporter',
            $claudeMd,
            'CLAUDE.md should document the Lazy Load Reporter feature'
        );
        $this->assertStringContainsString(
            '$lazyLoadReporter',
            $claudeMd,
            'CLAUDE.md should mention the $lazyLoadReporter property'
        );
    }

    #[Test]
    public function lazyLoadReporterPropertyExists(): void
    {
        $reflection = new \ReflectionClass(Model::class);
        $this->assertTrue(
            $reflection->hasProperty('lazyLoadReporter'),
            'Model should have a lazyLoadReporter property'
        );

        $property = $reflection->getProperty('lazyLoadReporter');
        $this->assertTrue(
            $property->isPublic(),
            'lazyLoadReporter should be public'
        );

        $this->assertTrue(
            $property->isStatic(),
            'lazyLoadReporter should be static'
        );
    }

    #[Test]
    public function lazyLoadReporterCanBeSetToNull(): void
    {
        Model::$lazyLoadReporter = null;
        $this->assertNull(
            Model::$lazyLoadReporter,
            'lazyLoadReporter should be settable to null'
        );
    }

    #[Test]
    public function lazyLoadReporterCanBeSetToClosure(): void
    {
        $messages = [];
        Model::$lazyLoadReporter = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        $this->assertIsCallable(
            Model::$lazyLoadReporter,
            'lazyLoadReporter should be settable to a closure'
        );

        // Test that the closure works
        if (Model::$lazyLoadReporter !== null) {
            (Model::$lazyLoadReporter)('Test message');
            $this->assertSame(['Test message'], $messages);
        }
    }

    #[Test]
    public function lazyLoadReporterCanBeSilenced(): void
    {
        Model::$lazyLoadReporter = fn () => null;

        $this->assertIsCallable(
            Model::$lazyLoadReporter,
            'lazyLoadReporter should be settable to a silencing closure'
        );

        // Test that the silencing closure works
        if (Model::$lazyLoadReporter !== null) {
            (Model::$lazyLoadReporter)('This should be silenced');
            // No exception should be thrown
        }
    }

    protected function tearDown(): void
    {
        // Reset to default after each test
        Model::$lazyLoadReporter = null;
    }
}
