<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Security\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidatorBetweenParamTest extends TestCase
{
    public static function malformedParams(): array
    {
        return [
            'non-numeric pair' => ['abc'],
            'trailing garbage' => ['1,abc'],
            'single number'    => ['5'],
            'empty'            => [''],
            'extra segment'    => ['1,2,3'],
            'reversed range'   => ['10,1'],
        ];
    }

    #[Test]
    #[DataProvider('malformedParams')]
    public function malformedParamsThrow(string $param): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::make(['value' => 5], ['value' => "between:{$param}"])->validate();
    }

    #[Test]
    public function validRangePassesForInBoundsValue(): void
    {
        $this->assertTrue(
            Validator::make(['value' => 5], ['value' => 'between:1,10'])->validate()
        );
    }

    #[Test]
    public function validRangeFailsForOutOfBoundsValue(): void
    {
        $this->assertFalse(
            Validator::make(['value' => 50], ['value' => 'between:1,10'])->validate()
        );
    }

    #[Test]
    public function validRangeMeasuresStringLength(): void
    {
        $this->assertTrue(
            Validator::make(['name' => 'hello'], ['name' => 'between:3,10'])->validate()
        );
        $this->assertFalse(
            Validator::make(['name' => 'hi'], ['name' => 'between:3,10'])->validate()
        );
    }
}
