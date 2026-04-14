<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use Fw\Auth\Auth;
use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;

class UserController extends Controller
{
    public function show(Request $request): Response
    {
        return Auth::user()->match(
            some: fn ($user) => $this->json($user),
            none: fn () => $this->json(['message' => 'Unauthenticated'], 401),
        );
    }
}
