# START HERE

Best practices for this part of the codebase are to use the following CLI commands.

- `php fw make:request StorePostRequest` — create a FormRequest with typed Rule objects wired up
- `php fw make:resource --schema=app/Schemas/Post.json` — generates `StoreXRequest` + `UpdateXRequest` from the schema
- `php fw check` — verifies every FormRequest implements `rules()` and no string-pipe rules slipped in

# BEWARE

Only read past here if you are unable to use the CLI.

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

See [controllers.md](controllers.md) for the full controller pattern including error display and redirect-after-store. FormRequests have two entry points depending on context:

| Method | Returns | Use in |
|--------|---------|--------|
| `fromRequest($request)` | validated object — throws `ValidationException` on failure | Web controllers (HTML forms) |
| `fromArray($data)` | `Result<array, array<string,string>>` — never throws | API controllers (JSON responses) |

### Web Controllers (throws on failure)

```php
use App\Requests\StorePostRequest;
use Fw\Validation\ValidationException;

public function store(Request $request): Response
{
    try {
        $data = StorePostRequest::fromRequest($request);
    } catch (ValidationException $e) {
        return $this->view('posts.create', [
            'errors' => $e->errors,   // array<string, string>
            'old'    => $request->all(),
        ]);
    }

    $post = Post::create($data->toArray());
    return $this->redirect('/posts/' . $post->id);
}
```

### API Controllers (Result-based, no exception)

```php
public function store(Request $request): Response
{
    $validated = StorePostRequest::fromArray($request->all());

    if ($validated->isErr()) {
        return $this->json(['errors' => $validated->unwrapErr()], 422);
    }

    $post = Post::create($validated->unwrapOr([]));
    return $this->json($post, 201);
}
```

`fromRequest()` validates and returns a typed object with public properties matching the field names. `fromArray()` validates and returns a `Result` wrapping the raw validated array.

### Error Shape

`$e->errors` is `array<string, string>` — **one message per field, never a list**. Rules evaluate in order; the first failure stops the chain for that field:

> **Wrong assumption — errors are NOT a list:**
> ```php
> // ❌ This is NOT what $e->errors looks like
> ['title' => ['Title is required.', 'Title must be at least 3 characters.']]
> ```

```php
// $e->errors example:
[
    'title'   => 'The title field is required.',
    'email'   => 'The email must be a valid email address.',
    'user_id' => 'The selected user_id does not exist.',
]
```

Access in views:

```php
// Check if a field has an error
isset($errors['title'])

// Display the error message
$e($errors['title'])
```

### Passing Old Input Back

Always pass `$request->all()` back on failure so form fields repopulate:

```php
return $this->view('posts.create', [
    'errors' => $e->errors,
    'old'    => $request->all(),
]);
```

In views, use `$old('field', '')` — it reads from the `$old` array passed by the controller, not from session.

## Available Rules

All rules are in `Fw\Validation\Rules`:

| Rule | Constructor | Validates |
|------|-------------|-----------|
| `Required` | `new Required` | Present and not empty string/null |
| `MinLength` | `new MinLength(3)` | String length ≥ N characters |
| `MaxLength` | `new MaxLength(255)` | String length ≤ N characters |
| `Email` | `new Email` | Valid email format |
| `Url` | `new Url` | Valid URL (must include scheme) |
| `In` | `new In(['draft', 'published'])` | Value is in the given list (strict comparison) |
| `InEnum` | `new InEnum(Status::class)` | Value matches a backed enum case value |
| `Regex` | `new Regex('/^[a-z]+$/')` | Value matches the pattern |
| `Between` | `new Between(1, 100)` | Numeric value between min and max (inclusive) |
| `Unique` | `new Unique('users', 'email')` | Value does not already exist in `table.column` |
| `Exists` | `new Exists('categories', 'id')` | Value exists in `table.column` |
| `Confirmed` | `new Confirmed` | Value matches `{field}_confirmation` input |
| `Numeric` | `new Numeric` | Value is numeric (int or float string) |
| `Integer` | `new Integer` | Value is a whole number |
| `Uuid` | `new Uuid` | Valid UUID v4 format |
| `Date` | `new Date` | Parseable date string |
| `Alpha` | `new Alpha` | Only `[a-zA-Z]` characters |
| `AlphaNumeric` | `new AlphaNumeric` | Only `[a-zA-Z0-9]` characters |
| `Nullable` | `new Nullable` | Allows null — stops rule chain if null |

### Rule Behaviour Notes

- Rules run in order. The first failure stops the chain for that field — only one error per field.
- `Nullable` must be listed **before** other rules if you want null to be valid: `[new Nullable, new MinLength(3)]`.
- `Between` operates on **numeric values** only. Cast the input to int/float before validating strings.
- `Unique` does a case-sensitive database lookup. It does not exclude the current record — use explicit conditional logic for update scenarios.
- `Exists` validates that the value exists in the given column. For `foreignId` fields, use `new Exists('users', 'id')`.
- Fields without `Required` are **optional** — if the field is absent or empty, remaining rules are skipped.

#### String-rule parameters

When using the string rule shorthand (`'between:1,10'`, `'min:3'`, etc.) the parameter format is enforced strictly — typos fail fast rather than silently passing empty values. For `between:` specifically, the rule raises `InvalidArgumentException` at validate-time for:

- non-numeric bounds (`between:abc`, `between:1,abc`)
- wrong segment count (`between:5`, `between:`, `between:1,2,3`)
- reversed range (`between:10,1`)

Use the typed `new Between(1, 10)` form in new code to catch the same mistakes at the type-checker instead of runtime.

For API validation patterns and the JSON error response format — see [errors.md](errors.md).

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
