<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\ErrorHandler;
use App\Core\Request;
use App\Models\AuditLog;
use App\Services\AuthService;

/** Allows the request if the user holds AT LEAST ONE of the listed permissions. */
final class AnyPermissionMiddleware implements Middleware
{
    /** @var string[] */
    private array $permissions;

    public function __construct(string ...$permissions)
    {
        $this->permissions = $permissions;
    }

    public function handle(Request $request): ?string
    {
        $user = AuthService::user();
        if ($user !== null) {
            if (array_intersect($this->permissions, AuthService::permissions())) {
                return null;
            }
            AuditLog::write('auth.access_denied', (int) $user['id'], 'route', null, ['path' => $request->path, 'needs_any' => $this->permissions]);
        }
        return ErrorHandler::renderError(403, $request);
    }
}
