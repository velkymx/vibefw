# Authentication

VibeFW provides session-based authentication for web apps and token-based authentication for APIs.

## Session Authentication

### Login

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;
use App\Models\User;
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

        return User::where('email', '=', $data->email)->first()->match(
            some: function ($user) use ($data) {
                if (!password_verify($data->password, $user->password)) {
                    return $this->view('auth.login', [
                        'errors' => ['email' => 'Invalid credentials'],
                    ]);
                }

                $this->app->initSession();
                $_SESSION['user'] = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ];

                $intended = $_SESSION['intended_url'] ?? '/dashboard';
                unset($_SESSION['intended_url']);

                return $this->redirect($intended);
            },
            none: fn() => $this->view('auth.login', [
                'errors' => ['email' => 'Invalid credentials'],
            ]),
        );
    }
}
```

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
public function logout(Request $request): Response
{
    $this->app->initSession();
    session_destroy();

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

Protect routes using typed middleware:

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

## API Authentication

### Token Generation

```php
use App\Models\PersonalAccessToken;

$token = PersonalAccessToken::create([
    'user_id' => $user->id,
    'name' => 'api-token',
    'token' => bin2hex(random_bytes(32)),
    'abilities' => ['read', 'write'],
    'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
]);

return $this->json(['token' => $token->token]);
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
