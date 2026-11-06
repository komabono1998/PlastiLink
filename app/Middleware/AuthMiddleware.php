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

        $user = AuthService::user();
        if ($user === null) {
            if ($request->method === 'GET') {
                $_SESSION['intended'] = $request->path;
            }
            return redirect('/login');
        }

        // Temporary password issued by staff: must be replaced before anything else.
        if ((int) $user['must_change_password'] === 1 && !in_array($request->path, ['/account/password', '/logout'], true)) {
            return redirect('/account/password');
        }
        return null;
    }
}
