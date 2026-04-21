<?php

declare(strict_types=1);

namespace App\Controllers\Api\Auth;

use App\Models\User;
use App\Requests\RegisterRequest;
use Fw\Auth\Auth;
use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Support\Hash;
use Fw\Validation\ValidationException;
use PDOException;

final class RegisterController extends Controller
{
    public function register(Request $request): Response
    {
        try {
            $data = RegisterRequest::fromRequest($request);
        } catch (ValidationException $e) {
            return $this->json([
                'message' => 'Registration failed',
                'errors' => $e->errors,
            ], 422);
        }

        try {
            $user = User::create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => Hash::make($data->password),
            ]);
        } catch (PDOException $e) {
            // SQLSTATE 23000 = unique constraint violation (duplicate email)
            if (str_starts_with($e->getCode(), '23')) {
                return $this->json([
                    'message' => 'Registration failed',
                    'errors' => ['email' => ['The email address is already registered.']],
                ], 422);
            }

            throw $e;
        }

        Auth::login($user);
        $token = $user->createToken('spa-register')->plainTextToken;

        return $this->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }
}
