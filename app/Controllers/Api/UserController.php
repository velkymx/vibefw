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
        return $this->json(Auth::user());
    }
}
