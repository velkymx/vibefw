<?php

declare(strict_types=1);

namespace Fw\Tests\Unit;

use Fw\Validation\FormRequest;
use Fw\Validation\Rule;
use Fw\Validation\Rules\Email;
use Fw\Validation\Rules\InEnum;
use Fw\Validation\Rules\MaxLength;
use Fw\Validation\Rules\MinLength;
use Fw\Validation\Rules\Required;
use Fw\Validation\Rules\Unique;
use Fw\Validation\Rules\Exists;
use Fw\Validation\ValidationException;
use Fw\Validation\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

enum TestStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

final class TypedValidationTest extends TestCase
{
    // ==========================================
    // NEW RULE CLASSES
    // ==========================================

    #[Test]
    public function minLengthRejectsShortStrings(): void
    {
        $rule = new MinLength(3);
        $this->assertNotNull($rule->validate('ab', 'name'));
        $this->assertNull($rule->validate('abc', 'name'));
        $this->assertNull($rule->validate('abcd', 'name'));
    }

    #[Test]
    public function minLengthSkipsNullValues(): void
    {
        $rule = new MinLength(3);
        $this->assertNull($rule->validate(null, 'name'));
        $this->assertNull($rule->validate('', 'name'));
    }

    #[Test]
    public function maxLengthRejectsLongStrings(): void
    {
        $rule = new MaxLength(5);
        $this->assertNotNull($rule->validate('abcdef', 'name'));
        $this->assertNull($rule->validate('abcde', 'name'));
        $this->assertNull($rule->validate('abc', 'name'));
    }

    #[Test]
    public function maxLengthSkipsNullValues(): void
    {
        $rule = new MaxLength(5);
        $this->assertNull($rule->validate(null, 'name'));
        $this->assertNull($rule->validate('', 'name'));
    }

    #[Test]
    public function inEnumAcceptsValidValues(): void
    {
        $rule = new InEnum(TestStatus::class);
        $this->assertNull($rule->validate('draft', 'status'));
        $this->assertNull($rule->validate('published', 'status'));
    }

    #[Test]
    public function inEnumRejectsInvalidValues(): void
    {
        $rule = new InEnum(TestStatus::class);
        $this->assertNotNull($rule->validate('archived', 'status'));
        $this->assertNotNull($rule->validate('DRAFT', 'status'));
    }

    #[Test]
    public function inEnumSkipsNullValues(): void
    {
        $rule = new InEnum(TestStatus::class);
        $this->assertNull($rule->validate(null, 'status'));
    }

    #[Test]
    public function minLengthImplementsRuleInterface(): void
    {
        $this->assertInstanceOf(Rule::class, new MinLength(1));
    }

    #[Test]
    public function maxLengthImplementsRuleInterface(): void
    {
        $this->assertInstanceOf(Rule::class, new MaxLength(1));
    }

    #[Test]
    public function inEnumImplementsRuleInterface(): void
    {
        $this->assertInstanceOf(Rule::class, new InEnum(TestStatus::class));
    }

    #[Test]
    public function uniqueImplementsRuleInterface(): void
    {
        $this->assertInstanceOf(Rule::class, new Unique('users', 'email'));
    }

    #[Test]
    public function existsImplementsRuleInterface(): void
    {
        $this->assertInstanceOf(Rule::class, new Exists('categories', 'id'));
    }

    // ==========================================
    // FORMREQUEST WITH rules() METHOD
    // ==========================================

    #[Test]
    public function formRequestUsesRulesMethod(): void
    {
        $data = ['title' => 'Hello World', 'email' => 'test@example.com'];

        $request = TestFormRequest::fromArray($data);

        $this->assertSame('Hello World', $request->title);
        $this->assertSame('test@example.com', $request->email);
    }

    #[Test]
    public function formRequestRejectsInvalidData(): void
    {
        $this->expectException(ValidationException::class);

        TestFormRequest::fromArray(['title' => '', 'email' => 'not-email']);
    }

    #[Test]
    public function formRequestRulesMethodIsRequired(): void
    {
        $ref = new \ReflectionClass(FormRequest::class);
        $method = $ref->getMethod('rules');

        $this->assertTrue($method->isAbstract());
    }

    // ==========================================
    // VALIDATOR TYPED RULES
    // ==========================================

    #[Test]
    public function validatorAcceptsTypedRuleArrays(): void
    {
        $validator = new Validator();

        $validated = $validator->validate(
            ['name' => 'John', 'email' => 'john@example.com'],
            [
                'name' => [new Required(), new MinLength(2)],
                'email' => [new Required(), new Email()],
            ]
        );

        $this->assertSame('John', $validated['name']);
        $this->assertSame('john@example.com', $validated['email']);
    }

    #[Test]
    public function validatorRejectsWithTypedRules(): void
    {
        $validator = new Validator();

        $this->expectException(ValidationException::class);

        $validator->validate(
            ['name' => 'J'],
            ['name' => [new Required(), new MinLength(3)]],
        );
    }
}

class TestFormRequest extends FormRequest
{
    public string $title;
    public string $email;

    public function rules(): array
    {
        return [
            'title' => [new Required(), new MinLength(3), new MaxLength(255)],
            'email' => [new Required(), new Email()],
        ];
    }
}
