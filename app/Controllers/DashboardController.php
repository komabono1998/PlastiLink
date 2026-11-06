<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Models\Hub;
use App\Models\LoginAttempt;
use App\Models\Picker;
use App\Models\Recycler;
use App\Models\User;
use App\Services\AuthService;
use App\Services\HubScope;

final class DashboardController extends Controller
{
    /** /dashboard: send each role to its own home. */
    public function index(Request $request): string
    {
        return redirect(AuthService::homeFor(AuthService::user()['role_slug']));
    }

    public function admin(Request $request): string
    {
        $byStatus = User::countByStatus();
        $pickers = Picker::countByStatus();
        $recyclers = Recycler::countByStatus();
        $hubs = Hub::countByStatus();
        return $this->page($request, 'admin/dashboard', 'Dashboard', [
            'usersTotal'     => array_sum($byStatus),
            'usersActive'    => $byStatus['active'] ?? 0,
            'usersPending'   => $byStatus['pending'] ?? 0,
            'usersSuspended' => $byStatus['suspended'] ?? 0,
            'pickersTotal'   => array_sum($pickers),
            'pickersPending' => $pickers['pending'] ?? 0,
            'recyclersTotal' => array_sum($recyclers),
            'recyclersPending' => $recyclers['pending'] ?? 0,
            'hubsActive'     => $hubs['active'] ?? 0,
            'byRole'         => User::countByRole(),
            'failed24h'      => LoginAttempt::failedSince('24 HOUR'),
            'recentLogins'   => LoginAttempt::recentSuccessful(5),
        ]);
    }

    public function hub(Request $request): string
    {
        $scope = HubScope::forUser(AuthService::user());
        $pickers = Picker::countByStatus($scope);
        return $this->page($request, 'hubs/dashboard', 'Hub dashboard', [
            'myHubs'         => Hub::list($scope),
            'pickersTotal'   => array_sum($pickers),
            'pickersPending' => $pickers['pending'] ?? 0,
        ]);
    }

    public function picker(Request $request): string
    {
        return $this->page($request, 'picker/dashboard', 'My dashboard');
    }

    public function recycler(Request $request): string
    {
        return $this->page($request, 'recyclers/dashboard', 'Recycler dashboard');
    }

    /** Sidebar for the current user: only links that exist and that they may open. */
    public static function nav(Request $request): array
    {
        $user = AuthService::user();
        $home = AuthService::homeFor($user['role_slug']);
        $can = static fn(string ...$p): bool => (bool) array_intersect($p, AuthService::permissions());

        $defs = [
            [$home, 'speedometer2', 'Dashboard', true, true],
            ['/approvals', 'patch-check', 'Approvals', $can('pickers.manage', 'recyclers.approve'), false],
            ['/users', 'people', 'Users', $can('users.view'), false],
            ['/pickers', 'person-badge', 'Waste pickers', $can('pickers.view'), false],
            ['/hubs', 'shop', 'Collection hubs', $can('hubs.view'), false],
            ['/recyclers', 'building', 'Recyclers', $can('recyclers.approve', 'recyclers.manage'), false],
            ['/neighbourhoods', 'geo-alt', 'Neighbourhoods', $can('neighbourhoods.manage'), false],
            ['/admin/audit', 'journal-text', 'Audit trail', $can('audit.view'), false],
            ['/account/password', 'key', 'Change password', true, false],
        ];
        $items = [];
        foreach ($defs as [$path, $icon, $label, $show, $exact]) {
            if (!$show) {
                continue;
            }
            $active = $exact ? $request->path === $path : ($request->path === $path || str_starts_with($request->path, $path . '/'));
            $items[] = ['href' => url($path), 'icon' => $icon, 'label' => $label, 'active' => $active];
        }
        return $items;
    }
}
