<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestMaxBodySizeTest extends TestCase
{
    private int $original = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = Request::getMaxBodySize();
    }

    protected function tearDown(): void
    {
        Request::setMaxBodySize($this->original);
        parent::tearDown();
    }

    #[Test]
    public function defaultIsTenMebibytes(): void
    {
        $this->assertSame(10 * 1024 * 1024, Request::getMaxBodySize());
    }

    #[Test]
    public function setterUpdatesMaxBodySize(): void
    {
        Request::setMaxBodySize(2048);
        $this->assertSame(2048, Request::getMaxBodySize());
    }

    #[Test]
    public function setterRejectsNonPositiveValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Request::setMaxBodySize(0);
    }

    #[Test]
    public function setterRejectsNegativeValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Request::setMaxBodySize(-1);
    }
}
