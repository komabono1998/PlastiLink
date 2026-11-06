<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Services\AuthService;

/** Requires a signed-in, active user. Guests are sent to /login and returned afterwards. */
final class AuthMiddleware implements Middleware
{
    public function handle(Request $request): ?string
    {
        // Authenticated pages must never be cached (Back button after logout).
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        if (AuthService::user() !== null) {
            return null;
        }
        if ($request->method === 'GET') {
            $_SESSION['intended'] = $request->path;
        }
        return redirect('/login');
    }
}
