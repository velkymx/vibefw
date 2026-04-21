<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Connection;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ConnectionIdClassCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Connection::reset();
    }

    protected function tearDown(): void
    {
        Connection::reset();
        parent::tearDown();
    }

    #[Test]
    public function connectionIdIsSharedAcrossInstances(): void
    {
        $config = ['driver' => 'sqlite', 'database' => ':memory:'];
        $a = Connection::createFresh($config);
        $b = Connection::createFresh($config);

        $this->assertSame(
            $a->connectionId,
            $b->connectionId,
            'Connection ID should be cached at class level, not regenerated per instance.',
        );
    }

    #[Test]
    public function connectionIdIsHexEncoded(): void
    {
        $conn = Connection::createFresh(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $conn->connectionId);
    }

    #[Test]
    public function connectionIdSurvivesReset(): void
    {
        $config = ['driver' => 'sqlite', 'database' => ':memory:'];
        $first = Connection::createFresh($config)->connectionId;
        Connection::reset();
        $second = Connection::createFresh($config)->connectionId;

        $this->assertSame($first, $second, 'Class-cached ID must persist across Connection::reset().');
    }
}
