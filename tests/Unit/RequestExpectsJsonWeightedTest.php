<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Core\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestExpectsJsonWeightedTest extends TestCase
{
    public static function acceptCases(): array
    {
        return [
            'html preferred over json via q' => [
                'application/json;q=0.5, text/html;q=0.9',
                false,
            ],
            'json preferred over html via q' => [
                'application/json;q=0.9, text/html;q=0.5',
                true,
            ],
            'tie prefers html' => [
                'application/json, text/html',
                false,
            ],
            'json alone still true' => [
                'application/json',
                true,
            ],
            'html alone still false' => [
                'text/html',
                false,
            ],
            'bare wildcard still true' => [
                '*/*',
                true,
            ],
            'html with low-q wildcard stays false' => [
                'text/html, */*;q=0.8',
                false,
            ],
            'json with low-q wildcard true' => [
                'application/json, */*;q=0.8',
                true,
            ],
            'browser-style accept (html wins)' => [
                'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                false,
            ],
            'json-zero q disables json' => [
                'application/json;q=0, text/html',
                false,
            ],
        ];
    }

    #[Test]
    #[DataProvider('acceptCases')]
    public function respectsAcceptWeights(string $accept, bool $expected): void
    {
        $request = new Request(headers: ['accept' => $accept]);

        $this->assertSame($expected, $request->expectsJson());
    }
}
