<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Security;

use Fw\Security\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * M5: Validator double-escape in error messages.
 *
 * addError() called htmlspecialchars() on :field and :param, but
 * view templates already escape via $e(). If a field name contained
 * an ampersand (e.g. "a&b"), the stored message had "&amp;amp;"
 * after double-escaping. After fix, messages are stored as plain
 * text — escaping is the view layer's responsibility.
 */
final class ValidatorDoubleEscapeTest extends TestCase
{
    #[Test]
    public function errorMessagesAreNotHtmlEscaped(): void
    {
        $v = Validator::make(
            ['user_email' => 'not-an-email'],
            ['user_email' => 'required|email']
        );
        $v->validate();

        $error = $v->firstError('user_email');
        $this->assertNotNull($error);
        $this->assertStringContainsString('user email', $error, 'Field name underscores should be replaced with spaces, not HTML-escaped');
        $this->assertStringNotContainsString('&amp;', $error, 'Error message should not contain HTML entities');
    }

    #[Test]
    public function paramWithSpecialCharsNotEscaped(): void
    {
        $v = Validator::make(
            ['name' => 'ab'],
            ['name' => 'min:5']
        );
        $v->validate();

        $error = $v->firstError('name');
        $this->assertNotNull($error);
        $this->assertStringContainsString('5', $error);
        $this->assertStringNotContainsString('&amp;', $error, 'Param should not be HTML-escaped in error message');
    }

    #[Test]
    public function ampersandInFieldNameNotDoubleEscaped(): void
    {
        $v = Validator::make(
            ['a&b' => ''],
            ['a&b' => 'required']
        );
        $v->validate();

        $error = $v->firstError('a&b');
        $this->assertNotNull($error);
        $this->assertStringContainsString('a&b', $error, 'Ampersand should be literal, not &amp;');
        $this->assertStringNotContainsString('&amp;', $error);
    }

    #[Test]
    public function addErrorDoesNotCallHtmlspecialchars(): void
    {
        $source = file_get_contents((new \ReflectionClass(Validator::class))->getFileName());
        $this->assertStringNotContainsString('htmlspecialchars', $source, 'Validator should not call htmlspecialchars — escaping belongs to the view layer');
    }
}
