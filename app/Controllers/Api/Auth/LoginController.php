<?php

declare(strict_types=1);

namespace App\Controllers\Api\Auth;

use App\Models\User;
use App\Requests\LoginRequest;
use Fw\Auth\TokenGuard;
use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Validation\ValidationException;

final class LoginController extends Controller
{
    public function login(Request $request): Response
    {
        try {
            $data = LoginRequest::fromRequest($request);
        } catch (ValidationException $e) {
            return $this->json([
                'message' => 'Validation failed',
                'errors' => $e->errors,
            ], 422);
        }

        // Token-only SPA auth: validate credentials without starting a session.
        // Auth::attempt is intentionally not called here — it starts a PHP session
        // which is incompatible with stateless API token authentication.
        $userOption = User::findByEmail($data->email);

        if ($userOption->isNone() || !$userOption->unwrapOr(null)->verifyPassword($data->password)) {
            return $this->json(['message' => 'Invalid credentials'], 401);
        }

        $user = $userOption->unwrapOr(null);
        $token = $user->createToken('spa-login')->plainTextToken;

        return $this->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request): Response
    {
        // Revoke the Bearer token. No session to clear — SPA auth is stateless.
        TokenGuard::currentToken()?->revoke();

        return $this->json(['message' => 'Logged out successfully']);
    }
}
