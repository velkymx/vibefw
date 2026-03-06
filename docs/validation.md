# Validation

VibeFW provides simple, declarative validation for request data.

## Basic Usage

```php
public function store(Request $request): Response
{
    $validation = $this->validate($request, [
        'title' => 'required|min:3|max:200',
        'email' => 'required|email',
        'content' => 'required|min:10',
    ]);

    if ($validation->isErr()) {
        return $this->view('posts.create', [
            'errors' => $validation->getError(),
            'old' => $request->all(),
        ]);
    }

    $data = $validation->getValue();
    $post = Post::create($data);

    return $this->redirect('/posts/' . $post->id);
}
```

## Available Rules

| Rule | Description | Example |
|------|-------------|---------|
| `required` | Field must be present and not empty | `'name' => 'required'` |
| `email` | Must be valid email format | `'email' => 'email'` |
| `min:n` | Minimum length (string) or value (numeric) | `'password' => 'min:8'` |
| `max:n` | Maximum length (string) or value (numeric) | `'title' => 'max:200'` |
| `numeric` | Must be numeric | `'age' => 'numeric'` |
| `alpha` | Only alphabetic characters | `'name' => 'alpha'` |
| `alphanumeric` | Only alphanumeric characters | `'username' => 'alphanumeric'` |
| `url` | Must be valid URL | `'website' => 'url'` |
| `uuid` | Must be valid UUID | `'id' => 'uuid'` |

## Combining Rules

Use the pipe character `|` to combine rules:

```php
$validation = $this->validate($request, [
    'username' => 'required|alphanumeric|min:3|max:20',
    'email' => 'required|email',
    'password' => 'required|min:8',
    'age' => 'numeric|min:0|max:150',
]);
```

## Result Type

Validation returns a `Result` type:

```php
$validation = $this->validate($request, $rules);

// Check for errors
if ($validation->isErr()) {
    $errors = $validation->getError();
    // ['email' => 'Email must be a valid email address']
}

// Check for success
if ($validation->isOk()) {
    $data = $validation->getValue();
    // ['email' => 'user@example.com', 'name' => 'John']
}

// Pattern matching
return $validation->match(
    ok: fn($data) => $this->createUser($data),
    err: fn($errors) => $this->view('form', compact('errors'))
);
```

## Error Messages

Default error messages are generated automatically:

```php
$errors = $validation->getError();
// [
//     'title' => 'Title is required',
//     'email' => 'Email must be a valid email address',
//     'password' => 'Password must be at least 8 characters',
// ]
```

## Displaying Errors

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

Pass old input back to the view:

```php
if ($validation->isErr()) {
    return $this->view('posts.create', [
        'errors' => $validation->getError(),
        'old' => $request->all(),
    ]);
}
```

In the view:

```php
<input type="text" name="title" value="<?= $e($old['title'] ?? '') ?>">
<textarea name="content"><?= $e($old['content'] ?? '') ?></textarea>
```

## Optional Fields

Fields without `required` are optional:

```php
$validation = $this->validate($request, [
    'name' => 'required|min:2',
    'website' => 'url',        // Optional, but if provided must be valid URL
    'bio' => 'max:500',        // Optional, but if provided must be ≤500 chars
]);
```

## Validation Examples

### User Registration

```php
$validation = $this->validate($request, [
    'name' => 'required|min:2|max:100',
    'email' => 'required|email',
    'password' => 'required|min:8',
]);
```

### Blog Post

```php
$validation = $this->validate($request, [
    'title' => 'required|min:3|max:200',
    'slug' => 'required|alphanumeric',
    'content' => 'required|min:50',
    'excerpt' => 'max:300',
]);
```

### Contact Form

```php
$validation = $this->validate($request, [
    'name' => 'required|min:2',
    'email' => 'required|email',
    'subject' => 'required|min:5|max:100',
    'message' => 'required|min:20|max:5000',
]);
```

### API Request

```php
$validation = $this->validate($request, [
    'user_id' => 'required|uuid',
    'amount' => 'required|numeric|min:0',
    'currency' => 'required|alpha|max:3',
]);
```

## Complete Controller Example

```php
class UserController extends Controller
{
    public function store(Request $request): Response
    {
        $validation = $this->validate($request, [
            'name' => 'required|min:2|max:100',
            'email' => 'required|email',
            'password' => 'required|min:8',
        ]);

        // Handle validation errors
        if ($validation->isErr()) {
            return $this->view('users.create', [
                'errors' => $validation->getError(),
                'old' => $this->except($request, ['password']),
            ]);
        }

        // Get validated data
        $data = $validation->getValue();

        // Check for existing user
        if (User::where('email', $data['email'])->first()->isSome()) {
            return $this->view('users.create', [
                'errors' => ['email' => 'Email already registered'],
                'old' => $this->except($request, ['password']),
            ]);
        }

        // Create user
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
        ]);

        return $this->redirect('/users/' . $user->id);
    }
}
```
