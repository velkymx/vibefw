<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Model as LegacyModel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LegacyModelDeprecationFixture extends LegacyModel
{
    protected static string $table = 'legacy_items';
}

final class LegacyModelDeprecationFixtureTwo extends LegacyModel
{
    protected static string $table = 'legacy_items_two';
}

final class LegacyModelDeprecationTest extends TestCase
{
    /** @var list<array{0: int, 1: string}> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->captured = [];
        set_error_handler(function (int $errno, string $message): bool {
            if ($errno === E_USER_DEPRECATED) {
                $this->captured[] = [$errno, $message];
                return true;
            }
            return false;
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        parent::tearDown();
    }

    #[Test]
    public function firstInstantiationEmitsDeprecationNotice(): void
    {
        new LegacyModelDeprecationFixture();

        $this->assertCount(1, $this->captured);
        $this->assertStringContainsString('Fw\\Database\\Model', $this->captured[0][1]);
        $this->assertStringContainsString('Fw\\Model\\Model', $this->captured[0][1]);
    }

    #[Test]
    public function subsequentInstantiationsOfSameSubclassDoNotSpam(): void
    {
        new LegacyModelDeprecationFixtureTwo();
        new LegacyModelDeprecationFixtureTwo();
        new LegacyModelDeprecationFixtureTwo();

        $this->assertCount(1, $this->captured, 'Deprecation must fire only once per concrete subclass.');
    }
}
