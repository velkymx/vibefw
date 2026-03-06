# Authentication

FW provides session-based authentication for web applications and token-based authentication for APIs.

## Session Authentication

### Login

```php
class LoginController extends Controller
{
    public function show(Request $request): Response
    {
        return $this->view('auth.login');
    }

    public function login(Request $request): Response
    {
        $validation = $this->validate($request, [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validation->isErr()) {
            return $this->view('auth.login')
                ->withErrors($validation->getError());
        }

        $data = $validation->getValue();

        // Find user by email
        $userOption = User::where('email', $data['email'])->first();

        if ($userOption->isNone()) {
            return $this->view('auth.login')
                ->withErrors(['email' => 'Invalid credentials']);
        }

        $user = $userOption->unwrap();

        // Verify password
        if (!password_verify($data['password'], $user->password)) {
            return $this->view('auth.login')
                ->withErrors(['email' => 'Invalid credentials']);
        }

        // Start session and store user
        $this->app->initSession();
        $_SESSION['user'] = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];

        // Redirect to intended URL or default
        $intended = $_SESSION['intended_url'] ?? '/dashboard';
        unset($_SESSION['intended_url']);

        return $this->redirect($intended);
    }
}
```

### Logout

```php
public function logout(Request $request): Response
{
    $this->app->initSession();
    session_destroy();

    return $this->redirect('/')
        ->with('status', 'Logged out successfully');
}
```

### Registration

```php
class RegisterController extends Controller
{
    public function show(Request $request): Response
    {
        return $this->view('auth.register');
    }

    public function register(Request $request): Response
    {
        $validation = $this->validate($request, [
            'name' => 'required|min:2',
            'email' => 'required|email',
            'password' => 'required|min:8',
        ]);

        if ($validation->isErr()) {
            return $this->view('auth.register')
                ->withErrors($validation->getError());
        }

        $data = $validation->getValue();

        // Check if email exists
        if (User::where('email', $data['email'])->first()->isSome()) {
            return $this->view('auth.register')
                ->withErrors(['email' => 'Email already registered']);
        }

        // Create user
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
        ]);

        // Auto-login
        $this->app->initSession();
        $_SESSION['user'] = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];

        return $this->redirect('/dashboard');
    }
}
```

## Checking Authentication

### In Controllers

```php
// Check if authenticated
if ($this->isAuthenticated()) {
    // User is logged in
}

// Get current user (returns Option)
$this->user()->match(
    some: fn($user) => "Hello, {$user->name}",
    none: fn() => "Not logged in"
);

// Get user or throw
$user = $this->user()->unwrap();
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

Protect routes requiring authentication:

```php
// In routes
$router->get('/dashboard', [DashboardController::class, 'index'])
    ->middleware('auth');

$router->group('/account', function (Router $router) {
    $router->get('/profile', [ProfileController::class, 'show']);
    $router->put('/profile', [ProfileController::class, 'update']);
}, ['auth']);
```

The `auth` middleware redirects unauthenticated users to `/login` and stores the intended URL.

## Guest Middleware

Prevent authenticated users from accessing certain pages:

```php
$router->get('/login', [LoginController::class, 'show'])
    ->middleware('guest');

$router->get('/register', [RegisterController::class, 'show'])
    ->middleware('guest');
```

## API Authentication

### Token Generation

```php
use App\Models\PersonalAccessToken;

// Create token for user
$token = PersonalAccessToken::create([
    'user_id' => $user->id,
    'name' => 'api-token',
    'token' => bin2hex(random_bytes(32)),
    'abilities' => ['read', 'write'],
    'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
]);

// Return token to user (only time it's visible)
return $this->json(['token' => $token->token]);
```

### Token Middleware

```php
$router->group('/api', function (Router $router) {
    $router->get('/user', [ApiController::class, 'user']);
    $router->get('/posts', [ApiController::class, 'posts']);
}, ['api.auth']);
```

### API Controller

```php
class ApiController extends Controller
{
    public function user(Request $request): Response
    {
        // Token user is available after api.auth middleware
        $user = $request->user;

        return $this->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }
}
```

### Making API Requests

```bash
curl -H "Authorization: Bearer your-token-here" \
     http://localhost:8000/api/user
```

## Token Abilities

Check token permissions:

```php
// Middleware
$router->post('/api/posts', [ApiController::class, 'store'])
    ->middleware(['api.auth', 'ability:write']);

// In controller
if ($request->token->can('write')) {
    // Has write permission
}

if ($request->token->cannot('admin')) {
    return $this->json(['error' => 'Forbidden'], 403);
}
```

## Password Reset

### Request Reset

```php
public function sendResetLink(Request $request): Response
{
    $validation = $this->validate($request, ['email' => 'required|email']);

    if ($validation->isErr()) {
        return $this->view('auth.forgot-password', ['errors' => $validation->getError()]);
    }

    $email = $validation->getValue()['email'];

    User::where('email', $email)->first()->map(function ($user) {
        $token = bin2hex(random_bytes(32));

        // Store token (create password_resets table)
        DB::execute(
            'INSERT INTO password_resets (email, token, created_at) VALUES (?, ?, ?)',
            [$user->email, hash('sha256', $token), date('Y-m-d H:i:s')]
        );

        // Send email with reset link
        // mail($user->email, 'Reset Password', "Click: /reset-password?token={$token}");
    });

    // Always show success (don't reveal if email exists)
    return $this->view('auth.forgot-password', [
        'status' => 'If your email exists, you will receive a reset link.',
    ]);
}
```

### Reset Password

```php
public function reset(Request $request): Response
{
    $validation = $this->validate($request, [
        'token' => 'required',
        'email' => 'required|email',
        'password' => 'required|min:8',
    ]);

    if ($validation->isErr()) {
        return $this->view('auth.reset-password', ['errors' => $validation->getError()]);
    }

    $data = $validation->getValue();

    // Verify token
    $reset = DB::queryOne(
        'SELECT * FROM password_resets WHERE email = ? AND token = ?',
        [$data['email'], hash('sha256', $data['token'])]
    );

    if (!$reset) {
        return $this->view('auth.reset-password', [
            'errors' => ['token' => 'Invalid or expired token'],
        ]);
    }

    // Update password
    User::where('email', $data['email'])->first()->map(function ($user) use ($data) {
        $user->update([
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
        ]);
    });

    // Delete used token
    DB::execute('DELETE FROM password_resets WHERE email = ?', [$data['email']]);

    return $this->redirect('/login')->with('status', 'Password reset successfully');
}
```

## User Model

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Fw\Model\Model;

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

    public function setPassword(string $password): void
    {
        $this->password = password_hash($password, PASSWORD_DEFAULT);
    }

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

## Routes Example

```php
// config/routes.php
return function (Router $router): void {
    // Guest only
    $router->get('/login', [LoginController::class, 'show'])->middleware('guest');
    $router->post('/login', [LoginController::class, 'login'])->middleware('guest');
    $router->get('/register', [RegisterController::class, 'show'])->middleware('guest');
    $router->post('/register', [RegisterController::class, 'register'])->middleware('guest');

    // Authenticated only
    $router->post('/logout', [LoginController::class, 'logout'])->middleware('auth');
    $router->get('/dashboard', [DashboardController::class, 'index'])->middleware('auth');

    // API
    $router->group('/api', function (Router $router) {
        $router->post('/login', [Api\AuthController::class, 'login']);
        $router->post('/register', [Api\AuthController::class, 'register']);

        $router->group('', function (Router $router) {
            $router->get('/user', [Api\UserController::class, 'show']);
            $router->post('/logout', [Api\AuthController::class, 'logout']);
        }, ['api.auth']);
    });
};
```
