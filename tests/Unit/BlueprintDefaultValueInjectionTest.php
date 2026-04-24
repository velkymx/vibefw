<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Database\Migration\Blueprint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Item #43: `Blueprint::default()` escaped single quotes by doubling
 * (`' -> ''`) but left backslashes alone. In MySQL default mode
 * (without NO_BACKSLASH_ESCAPES) the backslash is an escape char
 * inside string literals, so an input of `\' OR 1=1 --` escapes to
 * `\'' OR 1=1 --` and wraps to `'\'' OR 1=1 --'`, which MySQL reads
 * as a string of one char (`'`) followed by SQL code — classic
 * backslash-quote injection.
 *
 * [R48] rejects string defaults containing backslashes, NUL bytes,
 * or control characters with an InvalidArgumentException. Legitimate
 * content (including apostrophes like "O'Brien", commas, spaces,
 * unicode) still flows through the `''`-doubling path, which is
 * safe once backslashes are excluded.
 *
 * Non-string defaults (bool, null, int, float) are structural and
 * unaffected.
 */
final class BlueprintDefaultValueInjectionTest extends TestCase
{
    private function columnSql(Blueprint $bp): string
    {
        $p = (new ReflectionClass($bp))->getProperty('columns');
        /** @var array<int, string> $cols */
        $cols = $p->getValue($bp);
        return end($cols) ?: '';
    }

    #[Test]
    public function plainStringDefaultIsQuotedAndEscaped(): void
    {
        $bp = new Blueprint('users');
        $bp->string('name')->default("O'Brien");

        $sql = $this->columnSql($bp);
        $this->assertStringContainsString("DEFAULT 'O''Brien'", $sql);
    }

    #[Test]
    public function emptyStringDefaultStaysEmpty(): void
    {
        $bp = new Blueprint('users');
        $bp->string('name')->default('');

        $sql = $this->columnSql($bp);
        $this->assertStringContainsString("DEFAULT ''", $sql);
    }

    #[Test]
    public function backslashInStringDefaultIsRejected(): void
    {
        $bp = new Blueprint('users');
        $bp->string('name');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/default/i');

        // Classic MySQL backslash-quote injection vector.
        $bp->default("\\' OR 1=1 --");
    }

    #[Test]
    public function loneBackslashInStringDefaultIsRejected(): void
    {
        $bp = new Blueprint('users');
        $bp->string('name');

        $this->expectException(InvalidArgumentException::class);
        $bp->default('path\\to\\file');
    }

    #[Test]
    public function nulByteInStringDefaultIsRejected(): void
    {
        $bp = new Blueprint('users');
        $bp->string('name');

        $this->expectException(InvalidArgumentException::class);
        $bp->default("abc\x00def");
    }

    #[Test]
    public function controlCharInStringDefaultIsRejected(): void
    {
        $bp = new Blueprint('users');
        $bp->string('name');

        $this->expectException(InvalidArgumentException::class);
        $bp->default("abc\x07def"); // BEL
    }

    #[Test]
    public function newlineInStringDefaultIsRejected(): void
    {
        $bp = new Blueprint('users');
        $bp->string('name');

        $this->expectException(InvalidArgumentException::class);
        $bp->default("line1\nline2");
    }

    #[Test]
    public function boolDefaultUnaffected(): void
    {
        $bp = new Blueprint('users');
        $bp->boolean('active')->default(true);

        $sql = $this->columnSql($bp);
        $this->assertStringContainsString('DEFAULT 1', $sql);
    }

    #[Test]
    public function nullDefaultUnaffected(): void
    {
        $bp = new Blueprint('users');
        $bp->string('name')->default(null);

        $sql = $this->columnSql($bp);
        $this->assertStringContainsString('DEFAULT NULL', $sql);
    }

    #[Test]
    public function integerDefaultUnaffected(): void
    {
        $bp = new Blueprint('orders');
        $bp->integer('count')->default(42);

        $sql = $this->columnSql($bp);
        $this->assertStringContainsString('DEFAULT 42', $sql);
    }

    #[Test]
    public function unicodeStringDefaultAllowed(): void
    {
        $bp = new Blueprint('users');
        $bp->string('city')->default('São Paulo');

        $sql = $this->columnSql($bp);
        $this->assertStringContainsString("DEFAULT 'São Paulo'", $sql);
    }

    #[Test]
    public function injectionAttemptClassicQuotesStillDoubled(): void
    {
        // Pure single quotes without backslashes — the doubling escape
        // is sufficient, must remain accepted.
        $bp = new Blueprint('users');
        $bp->string('note')->default("it's ' fine");

        $sql = $this->columnSql($bp);
        $this->assertStringContainsString("DEFAULT 'it''s '' fine'", $sql);
    }
}
