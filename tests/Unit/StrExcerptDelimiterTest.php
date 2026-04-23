<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Support\Str;
use Fw\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Item #21: Str::excerpt() built a preg pattern with `/` as the
 * delimiter and `preg_quote($phrase, '/')` to escape the phrase. That
 * already escapes `/` inside the phrase, so the literal bug alleged
 * in the TODO isn't there — but the implicit coupling between the
 * delimiter character and the `preg_quote` delimiter argument is
 * fragile. If either end gets edited without updating the other, a
 * phrase containing the delimiter breaks the pattern.
 *
 * Switched to `#` as the delimiter (rarely in prose text) and added
 * regression tests exercising both `/` and `#` in the phrase to lock
 * the escape contract in place.
 */
final class StrExcerptDelimiterTest extends TestCase
{
    #[Test]
    public function phraseContainingSlashStillMatches(): void
    {
        $out = Str::excerpt('hello /world/ foo bar', '/world/', ['radius' => 20]);
        $this->assertSame('hello /world/ foo bar', $out);
    }

    #[Test]
    public function phraseContainingDelimiterHashStillMatches(): void
    {
        $out = Str::excerpt('look at #hashtag here', '#hashtag', ['radius' => 20]);
        $this->assertSame('look at #hashtag here', $out);
    }

    #[Test]
    public function phraseWithRegexMetacharsIsTreatedLiterally(): void
    {
        // `.*` and `+` in the phrase must be literal, not regex operators.
        $out = Str::excerpt('keep .* literal please', '.*', ['radius' => 100]);
        $this->assertStringContainsString('.*', (string) $out);

        $out2 = Str::excerpt('a+b+c is the formula', 'a+b+c', ['radius' => 50]);
        $this->assertStringContainsString('a+b+c', (string) $out2);
    }

    #[Test]
    public function phraseNotFoundReturnsNull(): void
    {
        $this->assertNull(Str::excerpt('hello world', 'missing'));
    }

    #[Test]
    public function unicodeFlagHandlesMultibytePhrase(): void
    {
        $out = Str::excerpt('pre café post', 'café', ['radius' => 10]);
        $this->assertStringContainsString('café', (string) $out);
    }
}
