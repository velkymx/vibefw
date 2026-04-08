# Validation

All validation uses typed Rule objects in FormRequest classes. String pipe rules (`'required|min:3'`) do not exist.

## FormRequest

Create a FormRequest for every form submission:

```bash
php fw make:request StorePostRequest
```

```php
<?php

declare(strict_types=1);

namespace App\Requests;

use Fw\Validation\FormRequest;
use Fw\Validation\Rule;
use Fw\Validation\Rules\Required;
use Fw\Validation\Rules\MinLength;
use Fw\Validation\Rules\MaxLength;
use Fw\Validation\Rules\Email;

class StorePostRequest extends FormRequest
{
    public string $title;
    public string $content;
    public string $email;

    /** @return array<string, list<Rule>> */
    public function rules(): array
    {
        return [
            'title'   => [new Required, new MinLength(3), new MaxLength(255)],
            'content' => [new Required],
            'email'   => [new Required, new Email],
        ];
    }
}
```

## Using in Controllers

```php
use App\Requests\StorePostRequest;
use Fw\Validation\ValidationException;

public function store(Request $request): Response
{
    try {
        $data = StorePostRequest::fromRequest($request);
    } catch (ValidationException $e) {
        return $this->view('posts.create', [
            'errors' => $e->errors,
            'old' => $request->all(),
        ]);
    }

    $post = Post::create($data->toArray());
    return $this->redirect('/posts/' . $post->id);
}
```

`fromRequest()` validates the request data against the rules. If validation fails, it throws `ValidationException` with an `errors` array keyed by field name.

## Available Rules

All rules are in `Fw\Validation\Rules`:

| Rule | Constructor | Description |
|------|-------------|-------------|
| `Required` | `new Required` | Field must be present and not empty |
| `MinLength` | `new MinLength(3)` | Minimum string length |
| `MaxLength` | `new MaxLength(255)` | Maximum string length |
| `Email` | `new Email` | Valid email format |
| `Url` | `new Url` | Valid URL format |
| `In` | `new In(['draft', 'published'])` | Value must be in list |
| `InEnum` | `new InEnum(Status::class)` | Value must be in enum |
| `Regex` | `new Regex('/^[a-z]+$/')` | Must match pattern |
| `Between` | `new Between(1, 100)` | Numeric range |
| `Unique` | `new Unique('users', 'email')` | Unique in database table |
| `Exists` | `new Exists('categories', 'id')` | Must exist in table |
| `Confirmed` | `new Confirmed` | Must match `{field}_confirmation` |
| `Numeric` | `new Numeric` | Must be numeric |
| `Integer` | `new Integer` | Must be integer |
| `Uuid` | `new Uuid` | Must be valid UUID |
| `Date` | `new Date` | Must be valid date |
| `Alpha` | `new Alpha` | Only alphabetic characters |
| `AlphaNumeric` | `new AlphaNumeric` | Only alphanumeric characters |
| `Nullable` | `new Nullable` | Allow null values |

## Why Typed Rules?

A typo in a string rule is a silent bug:
```php
// Silent — 'requred' is ignored, field passes validation
'title' => 'requred|min:3'
```

A typo in a typed rule is a fatal error:
```php
// Fatal error: Class "Fw\Validation\Rules\Requred" not found
'title' => [new Requred, new MinLength(3)]
```

AI models catch fatal errors. They don't catch silent bugs.

## Examples

### User Registration

```php
class StoreUserRequest extends FormRequest
{
    public string $name;
    public string $email;
    public string $password;

    public function rules(): array
    {
        return [
            'name'     => [new Required, new MinLength(2), new MaxLength(100)],
            'email'    => [new Required, new Email, new Unique('users', 'email')],
            'password' => [new Required, new MinLength(8), new Confirmed],
        ];
    }
}
```

### Blog Post

```php
class StorePostRequest extends FormRequest
{
    public string $title;
    public string $content;
    public string $status;

    public function rules(): array
    {
        return [
            'title'   => [new Required, new MinLength(3), new MaxLength(200)],
            'content' => [new Required, new MinLength(50)],
            'status'  => [new Required, new In(['draft', 'published'])],
        ];
    }
}
```

### API Request

```php
class StorePaymentRequest extends FormRequest
{
    public string $user_id;
    public string $amount;
    public string $currency;

    public function rules(): array
    {
        return [
            'user_id'  => [new Required, new Uuid],
            'amount'   => [new Required, new Numeric, new Between(0, 999999)],
            'currency' => [new Required, new Alpha, new MaxLength(3)],
        ];
    }
}
```

## Displaying Errors in Views

### All Errors

```php
<?php if (isset($errors) && !empty($errors)): ?>
    <div class="alert alert-danger">
        <ul>
            <?php foreach ($errors as $field => $message): ?>
                <li><?= $e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
```

### Per-Field Errors

```php
<div class="form-group">
    <label for="email">Email</label>
    <input type="email" name="email" id="email"
           class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
           value="<?= $e($old['email'] ?? '') ?>">
    <?php if (isset($errors['email'])): ?>
        <div class="invalid-feedback"><?= $e($errors['email']) ?></div>
    <?php endif; ?>
</div>
```

## Preserving Old Input

Pass old input back to the view on validation failure:

```php
try {
    $data = StorePostRequest::fromRequest($request);
} catch (ValidationException $e) {
    return $this->view('posts.create', [
        'errors' => $e->errors,
        'old' => $request->all(),
    ]);
}
```

In the view:

```php
<input type="text" name="title" value="<?= $e($old['title'] ?? '') ?>">
```

## Optional Fields

Fields without `Required` are optional. If provided, other rules still apply:

```php
public function rules(): array
{
    return [
        'name'    => [new Required, new MinLength(2)],
        'website' => [new Url],              // Optional, but if provided must be valid URL
        'bio'     => [new MaxLength(500)],   // Optional, max 500 chars
    ];
}
```
