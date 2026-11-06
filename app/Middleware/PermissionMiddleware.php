<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\ErrorHandler;
use App\Core\Request;
use App\Models\AuditLog;
use App\Services\AuthService;

/** Requires ALL listed permissions (from the database, via the user's role). Use after AuthMiddleware. */
final class PermissionMiddleware implements Middleware
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
            $missing = array_diff($this->permissions, AuthService::permissions());
            if (!$missing) {
                return null;
            }
            AuditLog::write('auth.access_denied', (int) $user['id'], 'route', null, ['path' => $request->path, 'missing' => array_values($missing)]);
        }
        return ErrorHandler::renderError(403, $request);
    }
}
