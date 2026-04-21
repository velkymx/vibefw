<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\PersonalAccessToken;
use App\Requests\CreateTokenRequest;
use Fw\Auth\Auth;
use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Validation\ValidationException;

final class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        return Auth::user()->match(
            some: function ($user) {
                $tokens = PersonalAccessToken::where('user_id', '=', $user->id)->get();
                return $this->json(['tokens' => $tokens]);
            },
            none: fn () => $this->json(['message' => 'Unauthenticated'], 401),
        );
    }

    public function store(Request $request): Response
    {
        try {
            $data = CreateTokenRequest::fromRequest($request);
        } catch (ValidationException $e) {
            return $this->json(['errors' => $e->errors], 422);
        }

        $abilities = $request->input('abilities', ['*']);

        return Auth::user()->match(
            some: function ($user) use ($data, $abilities) {
                $token = $user->createToken($data->name, $abilities);
                return $this->json([
                    'token' => $token->plainTextToken,
                    'message' => 'Token created successfully.',
                ], 201);
            },
            none: fn () => $this->json(['message' => 'Unauthenticated'], 401),
        );
    }

    public function destroy(Request $request, string $id): Response
    {
        return Auth::user()->match(
            some: function ($user) use ($id) {
                return PersonalAccessToken::where('id', '=', $id)
                    ->where('user_id', '=', $user->id)
                    ->first()
                    ->match(
                        some: function ($token) {
                            if (!$token->delete()) {
                                return $this->json(['message' => 'Token deletion failed.'], 500);
                            }
                            return $this->json(['message' => 'Token revoked.']);
                        },
                        none: fn () => $this->json(['message' => 'Token not found.'], 404),
                    );
            },
            none: fn () => $this->json(['message' => 'Unauthenticated'], 401),
        );
    }
}
