<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use Fw\Core\Controller;
use Fw\Core\Request;
use Fw\Core\Response;

// CUSTOMIZE: Add middleware or auth checks for the SPA shell
final class SpaController extends Controller
{
    public function index(Request $request): Response
    {
        $html = file_get_contents(BASE_PATH . '/frontend/dist/index.html');
        if ($html === false) {
            return new Response('Frontend not built. Run: npm run build in /frontend', 500)
                ->header('Content-Type', 'text/plain');
        }
        return new Response($html)->header('Content-Type', 'text/html');
    }
}
