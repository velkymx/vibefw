<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Async\AsyncHttp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Item #30: `executeAsync()` previously looked only at `[\r\n]` to
 * spot header injection, which misses a long tail of control bytes
 * (NUL, DEL, VT, FF, bare CR, bare LF, etc.) and any header *name*
 * that contains illegal characters per RFC 7230 §3.2.6.
 *
 * After [R35] both name and value are validated through
 * `assertValidHeader()`:
 *   - names must match the RFC 7230 token grammar and be non-empty
 *   - values must contain no byte < 0x20 (except HTAB 0x09) and no
 *     0x7F (DEL)
 *
 * These tests hit the validator directly via reflection rather than
 * the full `request()` path, because `request()` opens sockets /
 * touches the EventLoop and isn't unit-testable in isolation.
 */
final class AsyncHttpHeaderInjectionTest extends TestCase
{
    private static function assertValid(string $name, string $value): void
    {
        $m = (new ReflectionClass(AsyncHttp::class))->getMethod('assertValidHeader');
        $m->invoke(null, $name, $value);
    }

    /** @return array<string, array{string, string}> */
    public static function validHeaderProvider(): array
    {
        return [
            'standard'        => ['Content-Type', 'application/json'],
            'token chars'     => ["X-Custom_Header'*+!#$%&.^`|~", 'ok'],
            'numeric name'    => ['X-123', 'v'],
            'tab in value'    => ['X-Spaced', "a\tb"],
            'empty value'     => ['X-Empty', ''],
            'high ascii val'  => ['X-Obs', "caf\xC3\xA9"], // obs-text bytes allowed
            'printable punct' => ['X-Punct', '"<>/\\?={}[]()'],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function invalidHeaderProvider(): array
    {
        return [
            'CRLF in value'          => ['X-Bad', "v\r\nInjected: yes"],
            'bare LF in value'       => ['X-Bad', "v\nInjected: yes"],
            'bare CR in value'       => ['X-Bad', "v\rInjected: yes"],
            'NUL in value'           => ['X-Bad', "v\x00trail"],
            'DEL in value'           => ['X-Bad', "v\x7Ftrail"],
            'VT in value'            => ['X-Bad', "v\x0Btrail"],
            'FF in value'            => ['X-Bad', "v\x0Ctrail"],
            'bell in value'          => ['X-Bad', "v\x07trail"],
            'CRLF in name'           => ["X-Bad\r\nInjected", 'v'],
            'space in name'          => ['X-Bad Header', 'v'],
            'tab in name'            => ["X-Bad\tHeader", 'v'],
            'colon in name'          => ['X-Bad:Header', 'v'],
            'empty name'             => ['', 'v'],
            'high ascii name'        => ["X-caf\xC3\xA9", 'v'],
            'paren in name'          => ['X-(paren)', 'v'],
            'null byte name'         => ["X-Bad\x00", 'v'],
        ];
    }

    #[Test]
    #[DataProvider('validHeaderProvider')]
    public function acceptsWellFormedHeader(string $name, string $value): void
    {
        self::assertValid($name, $value);
        $this->addToAssertionCount(1);
    }

    #[Test]
    #[DataProvider('invalidHeaderProvider')]
    public function rejectsMalformedHeader(string $name, string $value): void
    {
        $this->expectException(RuntimeException::class);
        self::assertValid($name, $value);
    }
}
