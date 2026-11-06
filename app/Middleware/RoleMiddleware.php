<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\ErrorHandler;
use App\Core\Request;
use App\Models\AuditLog;
use App\Services\AuthService;

/** Allows only the listed roles (by slug). Use after AuthMiddleware. */
final class RoleMiddleware implements Middleware
{
    /** @var string[] */
    private array $roles;

    public function __construct(string ...$roles)
    {
        $this->roles = $roles;
    }

    public function handle(Request $request): ?string
    {
        $user = AuthService::user();
        if ($user !== null && in_array($user['role_slug'], $this->roles, true)) {
            return null;
        }
        if ($user !== null) {
            AuditLog::write('auth.access_denied', (int) $user['id'], 'route', null, ['path' => $request->path, 'needs_role' => $this->roles]);
        }
        return ErrorHandler::renderError(403, $request);
    }
}
