<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Requests\UpdatePasswordRequest;
use App\Requests\UpdateProfileRequest;
use Fw\Auth\Auth;
use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;
use Fw\Support\Hash;
use Fw\Validation\ValidationException;

final class ProfileController extends Controller
{
    public function update(Request $request): Response
    {
        try {
            $data = UpdateProfileRequest::fromRequest($request);
        } catch (ValidationException $e) {
            return $this->json(['errors' => $e->errors], 422);
        }

        return Auth::user()->match(
            some: function ($user) use ($data) {
                $user->fill($data->toArray())->save()->match(ok: fn () => null, err: fn ($e) => throw $e);
                return $this->json([
                    'user' => $user,
                    'message' => 'Profile updated successfully.',
                ]);
            },
            none: fn () => $this->json(['message' => 'Unauthenticated'], 401),
        );
    }

    public function updatePassword(Request $request): Response
    {
        try {
            $data = UpdatePasswordRequest::fromRequest($request);
        } catch (ValidationException $e) {
            return $this->json(['errors' => $e->errors], 422);
        }

        return Auth::user()->match(
            some: function ($user) use ($data) {
                if (!Hash::check($data->current_password, $user->password)) {
                    return $this->json([
                        'errors' => ['current_password' => 'The provided password does not match your current password.'],
                    ], 422);
                }

                $user->fill(['password' => Hash::make($data->password)])->save()->match(ok: fn () => null, err: fn ($e) => throw $e);

                return $this->json(['message' => 'Password updated successfully.']);
            },
            none: fn () => $this->json(['message' => 'Unauthenticated'], 401),
        );
    }

    public function destroy(Request $request): Response
    {
        $password = $request->input('password', '');

        return Auth::user()->match(
            some: function ($user) use ($password) {
                if (!Hash::check($password, $user->password)) {
                    return $this->json([
                        'errors' => ['password' => 'The provided password does not match our records.'],
                    ], 422);
                }

                if (!$user->delete()) {
                    return $this->json(['message' => 'Account deletion failed.'], 500);
                }

                Auth::logout();

                return $this->json(['message' => 'Account deleted successfully.']);
            },
            none: fn () => $this->json(['message' => 'Unauthenticated'], 401),
        );
    }
}
