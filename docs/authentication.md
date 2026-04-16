# Authentication

VibeFW provides session-based authentication for web apps and token-based authentication for APIs.

## Scaffolding Auth

Generate the controllers and form request classes:

```bash
php fw make:controller LoginController
php fw make:controller RegisterController
php fw make:request LoginRequest
php fw make:request RegisterRequest
```

## Session Authentication

### Login

Use `Auth::attempt()` for timing-safe credential validation. It always runs password hashing even when the user does not exist, preventing user enumeration via timing side-channels. On success it calls `Auth::login()` internally, which regenerates both the session ID and CSRF token.

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use Fw\Auth\Auth;
use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;
use App\Requests\LoginRequest;
use Fw\Validation\ValidationException;

class LoginController extends Controller
{
    public function showLogin(Request $request): Response
    {
        return $this->view('auth.login');
    }

    public function login(Request $request): Response
    {
        try {
            $data = LoginRequest::fromRequest($request);
        } catch (ValidationException $e) {
            return $this->view('auth.login', ['errors' => $e->errors]);
        }

        if (!Auth::attempt($data->email, $data->password)) {
            return $this->view('auth.login', [
                'errors' => ['email' => 'Invalid credentials'],
            ]);
        }

        $intended = $_SESSION['intended_url'] ?? '/dashboard';
        unset($_SESSION['intended_url']);

        return $this->redirect($intended);
    }
}
```

> **Security note:** Never call `password_verify()` manually in controllers. `Auth::attempt()` handles timing-safe comparison and session fixation prevention automatically. Manually constructing the session array bypasses CSRF token regeneration and session ID rotation.

### Login FormRequest

```php
<?php

declare(strict_types=1);

namespace App\Requests;

use Fw\Validation\FormRequest;
use Fw\Validation\Rules\Required;
use Fw\Validation\Rules\Email;

class LoginRequest extends FormRequest
{
    public string $email;
    public string $password;

    public function rules(): array
    {
        return [
            'email'    => [new Required, new Email],
            'password' => [new Required],
        ];
    }
}
```

### Logout

```php
use Fw\Auth\Auth;

public function logout(Request $request): Response
{
    Auth::logout();
    return $this->redirect('/');
}
```

### Registration

```php
public function register(Request $request): Response
{
    try {
        $data = RegisterRequest::fromRequest($request);
    } catch (ValidationException $e) {
        return $this->view('auth.register', ['errors' => $e->errors]);
    }

    // Check existing
    if (User::where('email', '=', $data->email)->first()->isSome()) {
        return $this->view('auth.register', [
            'errors' => ['email' => 'Email already registered'],
        ]);
    }

    $user = User::create([
        'name' => $data->name,
        'email' => $data->email,
        'password' => password_hash($data->password, PASSWORD_DEFAULT),
    ]);

    $this->app->initSession();
    $_SESSION['user'] = [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
    ];

    return $this->redirect('/dashboard');
}
```

## Checking Authentication

### In Controllers

```php
if ($this->isAuthenticated()) {
    // User is logged in
}

$this->user()->match(
    some: fn($user) => "Hello, {$user->name}",
    none: fn() => "Not logged in",
);
```

### In Views

```php
<?php if (isset($_SESSION['user'])): ?>
    <p>Welcome, <?= $e($_SESSION['user']['name']) ?></p>
    <form method="POST" action="/logout">
        <?= $csrf() ?>
        <button type="submit">Logout</button>
    </form>
<?php else: ?>
    <a href="/login">Login</a>
<?php endif; ?>
```

## Auth Middleware

Protect routes using typed middleware. For how middleware works and how to write custom middleware see [middleware.md](middleware.md).

```php
use App\Middleware\AuthMiddleware;
use App\Middleware\GuestMiddleware;

return function (Router $router): void {
    // Guest only (login/register pages)
    $router->with(GuestMiddleware::class, function (Router $r) {
        $r->get('/login', [LoginController::class, 'showLogin'], 'login');
        $r->post('/login', [LoginController::class, 'login']);
        $r->get('/register', [RegisterController::class, 'show'], 'register');
        $r->post('/register', [RegisterController::class, 'register']);
    });

    // Authenticated only
    $router->with(AuthMiddleware::class, function (Router $r) {
        $r->post('/logout', [LoginController::class, 'logout'], 'logout');
        $r->get('/dashboard', [DashboardController::class, 'index'], 'dashboard');
    });
};
```

## Session Cookie Configuration

### SameSite Policy

Control the `SameSite` attribute for session cookies via `SESSION_SAME_SITE` in `.env`:

```env
# Strict (default) — cookie not sent on cross-site requests at all
SESSION_SAME_SITE=Strict

# Lax — cookie sent on top-level navigations (GET links), not POST/AJAX
SESSION_SAME_SITE=Lax

# None — cookie sent cross-site; requires HTTPS and Secure flag
SESSION_SAME_SITE=None
```

Or in `config/app.php`:

```php
'session_same_site' => Env::string('SESSION_SAME_SITE', 'Strict'),
```

`Strict` is the secure default. Use `Lax` for apps embedded via links in third-party pages. `None` requires HTTPS and is rarely needed.

## API Authentication

### Token Generation

Use `ApiToken::create()` — never construct token records manually. The service generates a cryptographically random opaque token, hashes it for storage, and returns the plaintext only once.

```php
use Fw\Auth\ApiToken;

// In a controller or command
$newToken = ApiToken::create(
    user: $user,
    name: 'my-app',
    abilities: ['read', 'write'],
    // expiresAt: new DateTimeImmutable('+90 days'),  // optional
);

return $this->json($newToken->toArray());
// {"token": "abc123...", "token_id": 1, "name": "my-app", ...}
```

**Security:** tokens are opaque 256-bit random hex — no user ID embedded. Anyone holding the token cannot decode the owner's identity. Store the hash; never log or expose the plaintext after this response.

**Wrong — do not do this:**

```php
// ❌ Manual construction leaks no timing safety; exposes PII in token body
$token = PersonalAccessToken::create([
    'user_id' => $user->id,
    'token' => bin2hex(random_bytes(32)),
    ...
]);
```

### API Routes

```php
use App\Middleware\ApiAuthMiddleware;
use App\Middleware\TokenAbilityMiddleware;

$router->group('/api', function (Router $router) {
    // Public
    $router->get('/posts', [Api\PostController::class, 'index']);

    // Protected
    $router->with(ApiAuthMiddleware::class, function (Router $r) {
        $r->get('/user', [Api\UserController::class, 'show']);
        $r->post('/posts', [Api\PostController::class, 'store']);
    });
});
```

### Making API Requests

```bash
curl -H "Authorization: Bearer your-token-here" \
     http://localhost:8000/api/user
```

### Token Abilities

```php
if ($request->token->can('write')) {
    // Has write permission
}

if ($request->token->cannot('admin')) {
    return $this->json(['error' => 'Forbidden'], 403);
}
```

## User Model

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Fw\Model\Model;
use Fw\Model\Relations\HasMany;

class User extends Model
{
    protected static ?string $table = 'users';

    protected static array $fillable = [
        'name',
        'email',
        'password',
    ];

    protected static array $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class, 'user_id');
    }
}
```
