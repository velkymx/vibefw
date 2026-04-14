<?php

declare(strict_types=1);

namespace App\Controllers\Api\Auth;

use App\Requests\LoginRequest;
use Fw\Auth\Auth;
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

        if (Auth::attempt($data->email, $data->password)) {
            $user = Auth::user();
            $token = $user->createToken('spa-login')->plainTextToken;

            return $this->json([
                'user' => $user,
                'token' => $token,
            ]);
        }

        return $this->json(['message' => 'Invalid credentials'], 401);
    }

    public function logout(Request $request): Response
    {
        // Revoke the Bearer token before clearing the session so the token
        // cannot be reused after logout even if an attacker intercepted it.
        TokenGuard::currentToken()?->revoke();

        Auth::logout();
        return $this->json(['message' => 'Logged out successfully']);
    }
}
