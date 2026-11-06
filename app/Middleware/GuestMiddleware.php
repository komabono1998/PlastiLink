<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Services\AuthService;

/** For login/forgot pages: signed-in users go straight to their dashboard. */
final class GuestMiddleware implements Middleware
{
    public function handle(Request $request): ?string
    {
        $user = AuthService::user();
        return $user ? redirect(AuthService::homeFor($user['role_slug'])) : null;
    }
}
